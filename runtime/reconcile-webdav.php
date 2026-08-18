#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

function fail_webdav_reconcile($message)
{
  throw new RuntimeException($message);
}

function decrypt_webdav_credentials($blob, $hexKey)
{
  if (!preg_match('/^[a-f0-9]{64}$/', (string)$hexKey)) fail_webdav_reconcile('Connector-Schluessel ist ungueltig.');
  $outer = base64_decode(trim((string)$blob), true);
  $payload = is_string($outer) ? json_decode($outer, true) : null;
  if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) fail_webdav_reconcile('Connector-Zugangsdaten haben ein unbekanntes Format.');
  $iv = base64_decode((string)($payload['iv'] ?? ''), true);
  $tag = base64_decode((string)($payload['tag'] ?? ''), true);
  $cipher = base64_decode((string)($payload['data'] ?? ''), true);
  $plain = openssl_decrypt($cipher, 'aes-256-gcm', hex2bin($hexKey), OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false || $plain === '') fail_webdav_reconcile('Connector-Zugangsdaten konnten nicht entschluesselt werden.');
  $decoded = json_decode($plain, true);
  if (!is_array($decoded)) fail_webdav_reconcile('WebDAV-Verbindung besitzt kein kompatibles Secret-Format.');
  return array(
    'nextcloud_user'=>(string)($decoded['nextcloud_user'] ?? ''),
    'nextcloud_password'=>(string)($decoded['nextcloud_password'] ?? ''),
  );
}

function webdav_shell_value($value)
{
  return escapeshellarg((string)$value);
}

$pluginRoot = dirname(__DIR__);
$piwigoRoot = dirname($pluginRoot, 2);
$dbConfig = $piwigoRoot.'/local/config/database.inc.php';
$configDir = '/etc/bratonien-tools/nc-connector';
$stateRoot = '/var/lib/bratonien-tools/nc-connector';
$publicSourceRoot = rtrim($piwigoRoot, '/').'/_data/bratonien-tools/nc-webdav-source';

try
{
  if (!is_readable($dbConfig)) fail_webdav_reconcile('Piwigo-Datenbankkonfiguration ist nicht lesbar: '.$dbConfig);
  $conf = array();
  $prefixeTable = 'piwigo_';
  require $dbConfig;
  foreach (array('db_host','db_user','db_password','db_base') as $key)
  {
    if (!isset($conf[$key])) fail_webdav_reconcile('Piwigo-Datenbankkonfiguration ist unvollstaendig: '.$key);
  }

  $db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
  if ($db->connect_errno) fail_webdav_reconcile('Piwigo-Datenbank ist nicht erreichbar: '.$db->connect_error);
  $db->set_charset('utf8mb4');

  $keyResult = $db->query("SELECT value FROM `{$prefixeTable}config` WHERE param='bratonien_nc_connector_secret' LIMIT 1");
  if (!$keyResult || !$keyResult->num_rows) fail_webdav_reconcile('Connector-Schluessel wurde nicht gefunden.');
  $hexKey = trim((string)$keyResult->fetch_assoc()['value']);

  $table = $prefixeTable.'bratonien_tools_nc_connections';
  $rows = $db->query("SELECT id,name,adapter,config_json,secret_blob FROM `{$table}` ORDER BY id");
  if (!$rows) fail_webdav_reconcile('Connector-Verbindungen konnten nicht gelesen werden: '.$db->error);

  if (!is_dir($configDir) && !mkdir($configDir, 0700, true)) fail_webdav_reconcile('Runtime-Konfigurationsverzeichnis konnte nicht angelegt werden.');
  @chmod($configDir, 0700);
  if (!is_dir($publicSourceRoot) && !mkdir($publicSourceRoot, 0755, true)) fail_webdav_reconcile('WebDAV-Platzhalterbereich konnte nicht angelegt werden.');
  @chmod($publicSourceRoot, 0755);

  $known = array();

  while ($row = $rows->fetch_assoc())
  {
    $id = (int)$row['id'];
    $config = json_decode((string)$row['config_json'], true);
    if (!is_array($config)) $config = array();
    if ((string)$row['adapter'] !== 'remote') continue;
    if ((string)($config['source_mode'] ?? '') !== 'webdav-placeholder') continue;
    if (empty($config['parallel_test'])) continue;

    $known[$id] = true;
    try
    {
      $baseUrl = rtrim(trim((string)($config['nextcloud_url'] ?? '')), '/');
      $roots = isset($config['roots']) && is_array($config['roots']) ? $config['roots'] : array();
      if ($baseUrl === '') fail_webdav_reconcile('Nextcloud-Adresse fehlt.');
      if (!$roots) fail_webdav_reconcile('Keine WebDAV-Wurzeln gespeichert.');

      $credentials = decrypt_webdav_credentials((string)$row['secret_blob'], $hexKey);
      $user = trim($credentials['nextcloud_user']);
      $password = $credentials['nextcloud_password'];
      if ($user === '' || $password === '') fail_webdav_reconcile('Nextcloud-Zugangsdaten fehlen.');

      $stateDir = rtrim((string)($config['state_dir'] ?? ''), '/');
      if ($stateDir === '') $stateDir = $stateRoot.'/connection-'.$id;
      if (!is_dir($stateDir) && !mkdir($stateDir, 0750, true)) fail_webdav_reconcile('State-Verzeichnis konnte nicht angelegt werden.');
      @chmod($stateDir, 0750);

      $galleryRoot = rtrim((string)($config['parallel_gallery_root'] ?? ''), '/');
      if ($galleryRoot === '') $galleryRoot = rtrim($piwigoRoot, '/').'/galleries/bratonien-webdav-'.$id;
      $sourceDir = $publicSourceRoot.'/connection-'.$id;
      if (!is_dir($sourceDir) && !mkdir($sourceDir, 0755, true)) fail_webdav_reconcile('WebDAV-Platzhalterquelle konnte nicht angelegt werden.');
      @chmod($sourceDir, 0755);

      $base = $configDir.'/webdav-connection-'.$id;
      $passwordPath = $base.'.nextcloud-password';
      $rootsPath = $base.'.roots.tsv';
      $configPath = $base.'.conf';
      $statusFile = $stateDir.'/connector-status.json';
      $mappingFile = $stateDir.'/webdav-map.json';
      $manifestFile = $stateDir.'/webdav-manifest.tsv';
      $shadowMapFile = $stateDir.'/webdav-shadow-map.json';

      file_put_contents($passwordPath, $password."\n", LOCK_EX);
      @chmod($passwordPath, 0600);

      $rootLines = array('# webdav_path<TAB>display_name');
      foreach ($roots as $root)
      {
        $path = trim((string)($root['webdav_path'] ?? ''), '/');
        $display = trim((string)($root['display_name'] ?? ''));
        if ($path === '' || $display === '' || preg_match('/[\t\r\n]/', $path.$display)) fail_webdav_reconcile('Eine gespeicherte WebDAV-Wurzel ist ungueltig.');
        $rootLines[] = $path."\t".$display;
      }
      file_put_contents($rootsPath, implode("\n", $rootLines)."\n", LOCK_EX);
      @chmod($rootsPath, 0600);

      $lines = array(
        'PIWIGO_ROOT='.webdav_shell_value($piwigoRoot),
        'CONNECTION_ID='.$id,
        'SOURCE_MODE=webdav-placeholder',
        'WEBDAV_BASE_URL='.webdav_shell_value($baseUrl),
        'WEBDAV_USER='.webdav_shell_value($user),
        'WEBDAV_PASSWORD_FILE='.webdav_shell_value($passwordPath),
        'WEBDAV_ROOTS_FILE='.webdav_shell_value($rootsPath),
        'WEBDAV_SOURCE_DIR='.webdav_shell_value($sourceDir),
        'WEBDAV_MAPPING_FILE='.webdav_shell_value($mappingFile),
        'MANIFEST='.webdav_shell_value($manifestFile),
        'SHADOW_MAP_FILE='.webdav_shell_value($shadowMapFile),
        'GALLERY_ROOT='.webdav_shell_value($galleryRoot),
        'STATE_DIR='.webdav_shell_value($stateDir),
        'STATUS_FILE='.webdav_shell_value($statusFile),
        // In dieser Stufe wird nur der parallele Shadow Tree gebaut.
        // Piwigo-Registrierung wird erst nach Sichtpruefung bewusst freigeschaltet.
        'PIWIGO_SYNC_ENABLED=0',
      );
      file_put_contents($configPath, implode("\n", $lines)."\n", LOCK_EX);
      @chmod($configPath, 0600);

      $config['state_dir'] = $stateDir;
      $config['status_file'] = $statusFile;
      $config['parallel_gallery_root'] = $galleryRoot;
      $config['runtime'] = array(
        'mode'=>'parallel-webdav',
        'config'=>$configPath,
        'piwigo_sync_enabled'=>false,
        'reconciled_at'=>date('Y-m-d H:i:s'),
      );
      $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if (is_string($json))
      {
        $escaped = $db->real_escape_string($json);
        $db->query("UPDATE `{$table}` SET config_json='{$escaped}' WHERE id={$id} LIMIT 1");
      }
    }
    catch (Throwable $e)
    {
      fwrite(STDERR, "NC WebDAV #{$id}: ".$e->getMessage()."\n");
    }
  }

  foreach (glob($configDir.'/webdav-connection-*.conf') ?: array() as $path)
  {
    if (!preg_match('/webdav-connection-([0-9]+)\.conf$/', $path, $match)) continue;
    $id = (int)$match[1];
    if (isset($known[$id])) continue;
    foreach (glob($configDir.'/webdav-connection-'.$id.'.*') ?: array() as $stale) @unlink($stale);
  }

  exit(0);
}
catch (Throwable $e)
{
  fwrite(STDERR, "NC WebDAV Reconcile: ".$e->getMessage()."\n");
  exit(1);
}
