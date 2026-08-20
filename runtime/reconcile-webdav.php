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

function webdav_remove_generated_tree($path, $allowedRoot)
{
  $path = rtrim((string)$path, '/');
  $allowedRoot = rtrim((string)$allowedRoot, '/');
  if ($path === '' || $allowedRoot === '' || strpos($path, $allowedRoot.'/') !== 0 || !file_exists($path)) return;
  if (is_link($path) || is_file($path))
  {
    @unlink($path);
    return;
  }
  $items = scandir($path);
  if (is_array($items))
  {
    foreach ($items as $item)
    {
      if ($item === '.' || $item === '..') continue;
      webdav_remove_generated_tree($path.'/'.$item, $allowedRoot);
    }
  }
  @rmdir($path);
}

function webdav_source_fingerprint($baseUrl, $user, array $roots)
{
  $normalized = array();
  foreach ($roots as $root)
  {
    $normalized[] = array(
      'fileid'=>(int)($root['fileid'] ?? 0),
      'path'=>trim((string)($root['webdav_path'] ?? ''), '/'),
    );
  }
  usort($normalized, function($a, $b)
  {
    $cmp = $a['fileid'] <=> $b['fileid'];
    return $cmp !== 0 ? $cmp : strcmp($a['path'], $b['path']);
  });
  return hash('sha256', strtolower(rtrim((string)$baseUrl, '/'))."\n".strtolower(trim((string)$user))."\n".json_encode($normalized));
}

$pluginRoot = dirname(__DIR__);
$piwigoRoot = dirname($pluginRoot, 2);
$dbConfig = $piwigoRoot.'/local/config/database.inc.php';
$configDir = '/etc/bratonien-tools/nc-connector';
$stateRoot = '/var/lib/bratonien-tools/nc-connector';
$publicSourceRoot = rtrim($piwigoRoot, '/').'/_data/bratonien-tools/nc-webdav-source';
$publicGalleryRoot = rtrim($piwigoRoot, '/').'/_data/bratonien-tools/nc-webdav-gallery';

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
  $rows = $db->query("SELECT id,name,adapter,config_json,secret_blob FROM `{$table}` WHERE adapter='remote' ORDER BY id DESC");
  if (!$rows) fail_webdav_reconcile('WebDAV-Verbindungen konnten nicht gelesen werden: '.$db->error);

  foreach (array($configDir, $stateRoot, $publicSourceRoot, $publicGalleryRoot) as $dir)
  {
    if (!is_dir($dir) && !mkdir($dir, $dir === $configDir ? 0700 : 0750, true)) fail_webdav_reconcile('Runtime-Verzeichnis konnte nicht angelegt werden: '.$dir);
  }
  @chmod($configDir, 0700);
  @chmod($stateRoot, 0750);
  @chmod($publicSourceRoot, 0755);
  @chmod($publicGalleryRoot, 0755);

  $known = array();
  $seenFingerprints = array();

  while ($row = $rows->fetch_assoc())
  {
    $id = (int)$row['id'];
    $config = json_decode((string)$row['config_json'], true);
    if (!is_array($config)) $config = array();
    if ((string)($config['source_mode'] ?? '') !== 'webdav-placeholder') continue;

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

      $fingerprint = webdav_source_fingerprint($baseUrl, $user, $roots);
      if (isset($seenFingerprints[$fingerprint]))
      {
        fwrite(STDERR, "NC WebDAV #{$id}: identische Quelle bereits durch Verbindung #".$seenFingerprints[$fingerprint]." abgedeckt; doppelte Runtime wird unterdrueckt.\n");
        foreach (glob($configDir.'/webdav-connection-'.$id.'.*') ?: array() as $stale) @unlink($stale);
        webdav_remove_generated_tree($publicGalleryRoot.'/connection-'.$id, $publicGalleryRoot);
        continue;
      }
      $seenFingerprints[$fingerprint] = $id;
      $known[$id] = true;

      $stateDir = rtrim((string)($config['state_dir'] ?? ''), '/');
      if ($stateDir === '') $stateDir = $stateRoot.'/connection-'.$id;
      if (!is_dir($stateDir) && !mkdir($stateDir, 0750, true)) fail_webdav_reconcile('State-Verzeichnis konnte nicht angelegt werden.');
      @chmod($stateDir, 0750);

      $galleryRoot = rtrim((string)($config['parallel_gallery_root'] ?? ''), '/');
      $expectedGalleryRoot = $publicGalleryRoot.'/connection-'.$id;
      if ($galleryRoot === '' || strpos($galleryRoot, $publicGalleryRoot.'/') !== 0) $galleryRoot = $expectedGalleryRoot;
      if (!is_dir($galleryRoot) && !mkdir($galleryRoot, 0755, true)) fail_webdav_reconcile('WebDAV-Galeriebereich konnte nicht angelegt werden.');
      @chmod($galleryRoot, 0755);

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
        if ($display === '' || preg_match('/[\t\r\n]/', $path.$display)) fail_webdav_reconcile('Eine gespeicherte WebDAV-Wurzel ist ungueltig.');
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
        'PIWIGO_SYNC_ENABLED=1',
      );
      file_put_contents($configPath, implode("\n", $lines)."\n", LOCK_EX);
      @chmod($configPath, 0600);

      unset($config['parallel_test']);
      $config['state_dir'] = $stateDir;
      $config['status_file'] = $statusFile;
      $config['parallel_gallery_root'] = $galleryRoot;
      $config['source_fingerprint'] = $fingerprint;
      $config['runtime'] = array(
        'mode'=>'webdav',
        'config'=>$configPath,
        'piwigo_sync_enabled'=>true,
        'reconciled_at'=>date('Y-m-d H:i:s'),
      );
      $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if (!is_string($json)) fail_webdav_reconcile('WebDAV-Runtime-Konfiguration konnte nicht serialisiert werden.');
      $escaped = $db->real_escape_string($json);
      if (!$db->query("UPDATE `{$table}` SET enabled=1, config_json='{$escaped}' WHERE id={$id} AND adapter='remote' LIMIT 1"))
      {
        fail_webdav_reconcile('WebDAV-Runtime-Status konnte nicht gespeichert werden: '.$db->error);
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
