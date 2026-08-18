#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

function fail_reconcile($message)
{
  throw new RuntimeException($message);
}

function decrypt_reconcile($blob, $hexKey)
{
  if (!preg_match('/^[a-f0-9]{64}$/', (string)$hexKey)) fail_reconcile('Connector-Schluessel ist ungueltig.');
  $outer = base64_decode(trim((string)$blob), true);
  $payload = is_string($outer) ? json_decode($outer, true) : null;
  if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) fail_reconcile('Connector-Zugangsdaten haben ein unbekanntes Format.');
  $iv = base64_decode((string)($payload['iv'] ?? ''), true);
  $tag = base64_decode((string)($payload['tag'] ?? ''), true);
  $cipher = base64_decode((string)($payload['data'] ?? ''), true);
  $plain = openssl_decrypt($cipher, 'aes-256-gcm', hex2bin($hexKey), OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false || $plain === '') fail_reconcile('Connector-Zugangsdaten konnten nicht entschluesselt werden.');
  $decoded = json_decode($plain, true);
  if (!is_array($decoded))
  {
    return array('db_password'=>$plain,'piwigo_user'=>'','piwigo_password'=>'','api_key_id'=>'','api_key_secret'=>'');
  }
  return array(
    'db_password'=>(string)($decoded['db_password'] ?? ''),
    'piwigo_user'=>(string)($decoded['piwigo_user'] ?? ''),
    'piwigo_password'=>(string)($decoded['piwigo_password'] ?? ''),
    'api_key_id'=>(string)($decoded['api_key_id'] ?? ''),
    'api_key_secret'=>(string)($decoded['api_key_secret'] ?? ''),
  );
}

function encrypt_reconcile(array $credentials, $hexKey)
{
  $plain = json_encode(array(
    'v'=>2,
    'db_password'=>(string)$credentials['db_password'],
    'piwigo_user'=>(string)$credentials['piwigo_user'],
    'piwigo_password'=>(string)$credentials['piwigo_password'],
    'api_key_id'=>(string)$credentials['api_key_id'],
    'api_key_secret'=>(string)$credentials['api_key_secret'],
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($plain)) fail_reconcile('Connector-Zugangsdaten konnten nicht serialisiert werden.');
  $iv = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt($plain, 'aes-256-gcm', hex2bin($hexKey), OPENSSL_RAW_DATA, $iv, $tag);
  if ($cipher === false) fail_reconcile('Connector-Zugangsdaten konnten nicht verschluesselt werden.');
  return base64_encode(json_encode(array(
    'v'=>1,
    'iv'=>base64_encode($iv),
    'tag'=>base64_encode($tag),
    'data'=>base64_encode($cipher),
  )));
}

function write_runtime_status($piwigoRoot, $id, $stateDir, $message)
{
  $payload = json_encode(array(
    'state'=>'error',
    'message'=>'Runtime-Vorbereitung fehlgeschlagen',
    'timestamp'=>time(),
    'auth_mode'=>'failed',
    'api'=>array('state'=>'not_run','message'=>''),
    'fallback'=>array('state'=>'not_run','message'=>''),
    'error_detail'=>(string)$message,
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($payload)) return;
  $targets = array(rtrim($piwigoRoot, '/').'/_data/bratonien-tools/nc-connector-status/connection-'.$id.'.json');
  if ($stateDir !== '') $targets[] = rtrim($stateDir, '/').'/connector-status.json';
  foreach ($targets as $target)
  {
    $dir = dirname($target);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($target, $payload."\n", LOCK_EX);
    @chmod($target, 0644);
  }
}

function sql_reconcile(mysqli $db, $value)
{
  return $db->real_escape_string((string)$value);
}

function nextcloud_view_available(array $config, $password, $view)
{
  if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$view)) return false;
  $spec = array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w'));
  $command = array(
    'psql','-XAt','-v','ON_ERROR_STOP=1',
    '-h',(string)$config['host'],'-p',(string)$config['port'],'-U',(string)$config['user'],'-d',(string)$config['database'],
    '-c','SELECT 1 FROM '.$view.' LIMIT 1'
  );
  $env = $_ENV;
  $env['PGPASSWORD'] = (string)$password;
  $process = @proc_open($command, $spec, $pipes, null, $env);
  if (!is_resource($process)) return false;
  stream_get_contents($pipes[1]);
  stream_get_contents($pipes[2]);
  fclose($pipes[1]); fclose($pipes[2]);
  return proc_close($process) === 0;
}

function configured_access_user(array $config)
{
  foreach (array('access_user','nextcloud_access_user','showcase_user') as $key)
  {
    $value = trim((string)($config[$key] ?? ''));
    if ($value !== '') return $value;
  }
  return '';
}

$pluginRoot = dirname(__DIR__);
$piwigoRoot = dirname($pluginRoot, 2);
$dbConfig = $piwigoRoot.'/local/config/database.inc.php';
$configDir = '/etc/bratonien-tools/nc-connector';
$stateRoot = '/var/lib/bratonien-tools/nc-connector';

try
{
  if (!is_readable($dbConfig)) fail_reconcile('Piwigo-Datenbankkonfiguration ist nicht lesbar: '.$dbConfig);
  $conf = array();
  $prefixeTable = 'piwigo_';
  require $dbConfig;
  foreach (array('db_host','db_user','db_password','db_base') as $key)
  {
    if (!isset($conf[$key])) fail_reconcile('Piwigo-Datenbankkonfiguration ist unvollstaendig: '.$key);
  }

  $db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
  if ($db->connect_errno) fail_reconcile('Piwigo-Datenbank ist nicht erreichbar: '.$db->connect_error);
  $db->set_charset('utf8mb4');

  $keyResult = $db->query("SELECT value FROM `{$prefixeTable}config` WHERE param='bratonien_nc_connector_secret' LIMIT 1");
  if (!$keyResult || !$keyResult->num_rows) fail_reconcile('Connector-Schluessel wurde nicht gefunden.');
  $hexKey = trim((string)$keyResult->fetch_assoc()['value']);

  $table = $prefixeTable.'bratonien_tools_nc_connections';
  $rows = $db->query("SELECT id,name,adapter,enabled,takeover_state,config_json,secret_blob FROM `{$table}` ORDER BY id");
  if (!$rows) fail_reconcile('Connector-Verbindungen konnten nicht gelesen werden: '.$db->error);

  if (!is_dir($configDir) && !mkdir($configDir, 0700, true)) fail_reconcile('Runtime-Konfigurationsverzeichnis konnte nicht angelegt werden.');
  chmod($configDir, 0700);

  while ($row = $rows->fetch_assoc())
  {
    $id = (int)$row['id'];
    $config = json_decode((string)$row['config_json'], true);
    if (!is_array($config)) $config = array();
    $stateDir = rtrim((string)($config['state_dir'] ?? ''), '/');
    if ($stateDir === '') $stateDir = $stateRoot.'/connection-'.$id;

    try
    {
      if ((string)$row['adapter'] !== 'local') continue;
      $isActive = (int)$row['enabled'] === 1 && (string)$row['takeover_state'] === 'active';
      $accessUser = configured_access_user($config);
      $wizardConnection = (string)($config['origin'] ?? '') === 'native'
        && trim((string)($config['nextcloud_url'] ?? '')) !== ''
        && $accessUser !== '';
      $verification = isset($config['verification']) && is_array($config['verification']) ? $config['verification'] : null;
      $verificationFailed = is_array($verification) && empty($verification['ok']);
      if (!$isActive && (!$wizardConnection || $verificationFailed)) continue;

      foreach (array('host','port','database','user','source_view','activity_view','gallery_root') as $key)
      {
        if (trim((string)($config[$key] ?? '')) === '') fail_reconcile('Konfiguration unvollstaendig: '.$key.' fehlt.');
      }
      $storages = isset($config['storages']) && is_array($config['storages']) ? $config['storages'] : array();
      if (!$storages) fail_reconcile('Keine Storage-Zuordnungen gespeichert.');

      $credentials = decrypt_reconcile((string)$row['secret_blob'], $hexKey);
      if ($credentials['db_password'] === '') fail_reconcile('Datenbankpasswort fehlt.');

      $sourceMode = trim((string)($config['source_mode'] ?? ''));
      if ($sourceMode === 'user-filesystem')
      {
        @unlink($configDir.'/connection-'.$id.'.conf');
        fail_reconcile('Diese Verbindung verwendet den verworfenen experimentellen Benutzerpfad-Modus. Bitte die Verbindung mit dem aktuellen Assistenten neu anlegen.');
      }

      if ($sourceMode === '' || $sourceMode === 'legacy-view')
      {
        $canMigrate = $accessUser !== ''
          && nextcloud_view_available($config, $credentials['db_password'], 'piwigo_connector_shares')
          && nextcloud_view_available($config, $credentials['db_password'], 'piwigo_connector_activity');
        if ($canMigrate)
        {
          $sourceMode = 'user-shares';
          $config['source_mode'] = $sourceMode;
          $config['source_view'] = 'piwigo_connector_shares';
          $config['activity_view'] = 'piwigo_connector_activity';
          $config['access_user'] = $accessUser;
          $config['nextcloud_access_user'] = $accessUser;
          unset($config['showcase_user']);
          echo "NC Connector: Verbindung #{$id} auf generische benutzergefilterte Quellen migriert.\n";
        }
        else
        {
          $sourceMode = 'legacy-view';
          $config['source_mode'] = $sourceMode;
        }
      }

      if (!in_array($sourceMode, array('legacy-view','user-shares','selected-fileids'), true)) fail_reconcile('Unbekannter Quellenmodus: '.$sourceMode);
      if ($sourceMode !== 'legacy-view' && $accessUser === '') fail_reconcile('Für die verbindungsbezogene Quelle fehlt der Nextcloud-Benutzer.');

      $roots = isset($config['roots']) && is_array($config['roots']) ? $config['roots'] : array();
      if ($sourceMode === 'selected-fileids' && !$roots) fail_reconcile('Für die Verbindung sind keine ausgewählten Nextcloud-Datei-IDs gespeichert.');

      if (!$isActive && !array_key_exists('api_enabled', $config))
      {
        $hasFallback = $credentials['piwigo_user'] !== '' && $credentials['piwigo_password'] !== '';
        if (!$hasFallback) fail_reconcile('Die Verbindung besitzt weder einen eigenen API-Zugang noch einen eigenen Fallback.');
        $credentials['api_key_id'] = '';
        $credentials['api_key_secret'] = '';
        $config['piwigo_auth'] = 'connection-scoped';
        $config['api_enabled'] = false;
        $row['secret_blob'] = encrypt_reconcile($credentials, $hexKey);
      }

      $connectionScoped = (string)($config['piwigo_auth'] ?? '') === 'connection-scoped' || array_key_exists('api_enabled', $config);
      if ($connectionScoped)
      {
        $apiAvailable = !empty($config['api_enabled']) && $credentials['api_key_id'] !== '' && $credentials['api_key_secret'] !== '';
        $fallbackAvailable = $credentials['piwigo_user'] !== '' && $credentials['piwigo_password'] !== '';
        if (!$apiAvailable && !$fallbackAvailable) fail_reconcile('Kein verbindungseigener Piwigo-Zugang gespeichert.');
      }

      if (!is_dir($stateDir) && !mkdir($stateDir, 0750, true)) fail_reconcile('State-Verzeichnis konnte nicht angelegt werden.');
      chmod($stateDir, 0750);

      $base = $configDir.'/connection-'.$id;
      $dbPasswordPath = $base.'.db-password';
      $piwigoPasswordPath = $base.'.piwigo-password';
      $storagePath = $base.'.storages.tsv';
      $rootsPath = $base.'.roots.tsv';
      $configPath = $base.'.conf';
      $statusFile = $stateDir.'/connector-status.json';

      file_put_contents($dbPasswordPath, $credentials['db_password']."\n", LOCK_EX);
      chmod($dbPasswordPath, 0600);

      $fallbackAvailable = $credentials['piwigo_user'] !== '' && $credentials['piwigo_password'] !== '';
      if ($fallbackAvailable)
      {
        file_put_contents($piwigoPasswordPath, $credentials['piwigo_password']."\n", LOCK_EX);
        chmod($piwigoPasswordPath, 0600);
      }
      else @unlink($piwigoPasswordPath);

      $storageLines = array('# storage_id<TAB>source_prefix<TAB>local_mount<TAB>include_prefix');
      foreach ($storages as $storage)
      {
        $storageLines[] = (string)($storage['storage_id'] ?? '')."\t"
          .trim((string)($storage['source_prefix'] ?? ''), '/')."\t"
          .(string)($storage['local_mount'] ?? '')."\t"
          .trim((string)($storage['include_prefix'] ?? ''), '/');
      }
      file_put_contents($storagePath, implode("\n", $storageLines)."\n", LOCK_EX);
      chmod($storagePath, 0600);

      if ($sourceMode === 'selected-fileids')
      {
        $rootLines = array('# fileid<TAB>display_name');
        foreach ($roots as $root)
        {
          $fileid = (int)($root['fileid'] ?? 0);
          $display = trim((string)($root['display_name'] ?? ''));
          if ($fileid < 1 || $display === '' || preg_match('/[\t\r\n]/', $display)) fail_reconcile('Eine gespeicherte Nextcloud-Quelle ist ungueltig.');
          $rootLines[] = $fileid."\t".$display;
        }
        file_put_contents($rootsPath, implode("\n", $rootLines)."\n", LOCK_EX);
        chmod($rootsPath, 0600);
      }
      else @unlink($rootsPath);

      $lines = array(
        'PIWIGO_ROOT='.$piwigoRoot,
        'GALLERY_ROOT='.(string)$config['gallery_root'],
        'STATE_DIR='.$stateDir,
        'STATUS_FILE='.$statusFile,
        'NC_DB_HOST='.(string)$config['host'],
        'NC_DB_PORT='.(string)$config['port'],
        'NC_DB_NAME='.(string)$config['database'],
        'NC_DB_USER='.(string)$config['user'],
        'NC_DB_VIEW='.(string)$config['source_view'],
        'NC_ACTIVITY_VIEW='.(string)$config['activity_view'],
        'NC_DB_PASSWORD_FILE='.$dbPasswordPath,
        'STORAGE_CONFIG='.$storagePath,
        'SOURCE_MODE='.$sourceMode,
        'QUIET_SECONDS='.(int)($config['quiet_seconds'] ?? 120),
        'MAX_WAIT_SECONDS='.(int)($config['max_wait_seconds'] ?? 900),
        'FULL_SYNC_SECONDS='.(int)($config['full_sync_seconds'] ?? 86400),
        'PIWIGO_SYNC_ENABLED=1',
      );
      if ($sourceMode !== 'legacy-view') $lines[] = 'ACCESS_USER='.escapeshellarg($accessUser);
      if ($sourceMode === 'selected-fileids') $lines[] = 'ROOTS_CONFIG='.$rootsPath;
      if ($fallbackAvailable)
      {
        $lines[] = 'PIWIGO_SYNC_USER='.$credentials['piwigo_user'];
        $lines[] = 'PIWIGO_SYNC_PASSWORD_FILE='.$piwigoPasswordPath;
      }
      file_put_contents($configPath, implode("\n", $lines)."\n", LOCK_EX);
      chmod($configPath, 0600);

      $config['state_dir'] = $stateDir;
      $config['status_file'] = $statusFile;
      $config['runtime'] = array('mode'=>'shared-runner','config'=>$configPath,'reconciled_at'=>date('Y-m-d H:i:s'));
      $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if (!is_string($json)) fail_reconcile('Connector-Konfiguration konnte nicht serialisiert werden.');
      $now = date('Y-m-d H:i:s');
      $sql = "UPDATE `{$table}` SET enabled=1,takeover_state='active',config_json='".sql_reconcile($db,$json)."',secret_blob='".sql_reconcile($db,$row['secret_blob'])."',updated='".sql_reconcile($db,$now)."' WHERE id=".$id;
      if (!$db->query($sql)) fail_reconcile('Runtime-Status konnte nicht gespeichert werden: '.$db->error);
      if (!$isActive) echo "NC Connector: Verbindung #{$id} ({$row['name']}) automatisch in die gemeinsame Runtime uebernommen.\n";
    }
    catch (Throwable $e)
    {
      write_runtime_status($piwigoRoot, $id, $stateDir, $e->getMessage());
      fwrite(STDERR, "NC Connector #{$id}: ".$e->getMessage()."\n");
    }
  }

  exit(0);
}
catch (Throwable $e)
{
  fwrite(STDERR, "NC Connector Reconcile: ".$e->getMessage()."\n");
  exit(1);
}
