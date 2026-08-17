#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "Dieses Hilfsprogramm darf nur auf der Kommandozeile ausgefuehrt werden.\n");
  exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0)
{
  fwrite(STDERR, "Bitte als root ausfuehren.\n");
  exit(1);
}
if ($argc !== 2 || !preg_match('/^[1-9][0-9]*$/', $argv[1]))
{
  fwrite(STDERR, "Aufruf: php nc-connector-install.php <connection-id>\n");
  exit(1);
}

$id = (int)$argv[1];
$pluginRoot = __DIR__;
$piwigoRoot = dirname(__DIR__, 2);
$dbConfig = $piwigoRoot.'/local/config/database.inc.php';
$configDir = '/etc/bratonien-tools/nc-connector';
$stateRoot = '/var/lib/bratonien-tools/nc-connector';
$servicePath = '/etc/systemd/system/bratonien-nc-connector.service';
$timerPath = '/etc/systemd/system/bratonien-nc-connector.timer';

function fail_install($message)
{
  throw new RuntimeException($message);
}

function run_install(array $command, $allowFailure = false)
{
  $spec = array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
  );
  $process = proc_open($command, $spec, $pipes);
  if (!is_resource($process))
  {
    fail_install('Prozess konnte nicht gestartet werden: '.implode(' ', $command));
  }
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit = proc_close($process);
  if ($exit !== 0 && !$allowFailure)
  {
    $detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
    fail_install('Befehl fehlgeschlagen ('.$exit.'): '.implode(' ', $command).($detail !== '' ? "\n".$detail : ''));
  }
  return array('exit'=>$exit, 'stdout'=>(string)$stdout, 'stderr'=>(string)$stderr);
}

function decrypt_install_credentials($blob, $hexKey)
{
  if (!preg_match('/^[a-f0-9]{64}$/', $hexKey))
  {
    fail_install('Connector-Schluessel ist ungueltig.');
  }
  $outer = base64_decode(trim((string)$blob), true);
  $payload = is_string($outer) ? json_decode($outer, true) : null;
  if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1)
  {
    fail_install('Connector-Zugangsdaten haben ein unbekanntes Format.');
  }
  $iv = base64_decode((string)($payload['iv'] ?? ''), true);
  $tag = base64_decode((string)($payload['tag'] ?? ''), true);
  $cipher = base64_decode((string)($payload['data'] ?? ''), true);
  $plain = openssl_decrypt($cipher, 'aes-256-gcm', hex2bin($hexKey), OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false || $plain === '')
  {
    fail_install('Connector-Zugangsdaten konnten nicht entschluesselt werden.');
  }
  $decoded = json_decode($plain, true);
  if (!is_array($decoded) || empty($decoded['db_password']) || empty($decoded['piwigo_user']) || empty($decoded['piwigo_password']))
  {
    fail_install('Fuer eine native Aktivierung muessen Datenbank- und Piwigo-Zugangsdaten vollstaendig gespeichert sein.');
  }
  return $decoded;
}

function sql_install(mysqli $db, $value)
{
  return $db->real_escape_string((string)$value);
}

try
{
  if (!is_readable($dbConfig))
  {
    fail_install('Piwigo-Datenbankkonfiguration nicht lesbar: '.$dbConfig);
  }
  $conf = array();
  $prefixeTable = 'piwigo_';
  require $dbConfig;
  foreach (array('db_host','db_user','db_password','db_base') as $key)
  {
    if (!isset($conf[$key])) fail_install('Piwigo-Datenbankkonfiguration enthaelt '.$key.' nicht.');
  }

  $db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
  if ($db->connect_errno) fail_install('Piwigo-Datenbank nicht erreichbar: '.$db->connect_error);
  $db->set_charset('utf8mb4');

  $table = $prefixeTable.'bratonien_tools_nc_connections';
  $result = $db->query("SELECT id, name, adapter, enabled, takeover_state, config_json, secret_blob FROM `".$table."` WHERE id=".$id." LIMIT 1");
  if (!$result || !$result->num_rows) fail_install('Connector-Verbindung #'.$id.' wurde nicht gefunden.');
  $row = $result->fetch_assoc();
  if ((string)$row['adapter'] !== 'local') fail_install('Native Aktivierung ist derzeit nur fuer lokale Verbindungen verfuegbar.');
  if ((string)$row['takeover_state'] !== 'verified' || (int)$row['enabled'] !== 0)
  {
    fail_install('Die Verbindung muss zuerst erfolgreich verifiziert und deaktiviert sein.');
  }

  $config = json_decode((string)$row['config_json'], true);
  if (!is_array($config) || empty($config['verification']['ok'])) fail_install('Erfolgreiche Verifikation fehlt.');
  foreach (array('host','port','database','user','source_view','activity_view','gallery_root') as $key)
  {
    if (trim((string)($config[$key] ?? '')) === '') fail_install('Connector-Konfiguration ist unvollstaendig: '.$key.' fehlt.');
  }

  $keyResult = $db->query("SELECT value FROM `".$prefixeTable."config` WHERE param='bratonien_nc_connector_secret' LIMIT 1");
  if (!$keyResult || !$keyResult->num_rows) fail_install('Connector-Schluessel wurde in Piwigo nicht gefunden.');
  $keyRow = $keyResult->fetch_assoc();
  $credentials = decrypt_install_credentials((string)$row['secret_blob'], (string)$keyRow['value']);

  $stateDir = $stateRoot.'/connection-'.$id;
  $statusFile = $stateDir.'/connector-status.json';
  if (!is_dir($configDir) && !mkdir($configDir, 0700, true)) fail_install('Konfigurationsverzeichnis konnte nicht angelegt werden.');
  if (!is_dir($stateDir) && !mkdir($stateDir, 0750, true)) fail_install('State-Verzeichnis konnte nicht angelegt werden.');
  chmod($configDir, 0700);
  chmod($stateDir, 0750);

  $base = $configDir.'/connection-'.$id;
  $dbPasswordPath = $base.'.db-password';
  $piwigoPasswordPath = $base.'.piwigo-password';
  $storagePath = $base.'.storages.tsv';
  $configPath = $base.'.conf';

  file_put_contents($dbPasswordPath, (string)$credentials['db_password']."\n", LOCK_EX);
  file_put_contents($piwigoPasswordPath, (string)$credentials['piwigo_password']."\n", LOCK_EX);
  chmod($dbPasswordPath, 0600);
  chmod($piwigoPasswordPath, 0600);

  $storageLines = array('# storage_id<TAB>source_prefix<TAB>local_mount');
  foreach ((array)($config['storages'] ?? array()) as $storage)
  {
    $storageLines[] = (string)($storage['storage_id'] ?? '')."\t".(string)($storage['source_prefix'] ?? '')."\t".(string)($storage['local_mount'] ?? '');
  }
  if (count($storageLines) === 1) fail_install('Keine Storage-Zuordnungen gespeichert.');
  file_put_contents($storagePath, implode("\n", $storageLines)."\n", LOCK_EX);
  chmod($storagePath, 0600);

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
    'QUIET_SECONDS='.(int)($config['quiet_seconds'] ?? 120),
    'MAX_WAIT_SECONDS='.(int)($config['max_wait_seconds'] ?? 900),
    'FULL_SYNC_SECONDS='.(int)($config['full_sync_seconds'] ?? 86400),
    'PIWIGO_SYNC_ENABLED=1',
    'PIWIGO_SYNC_USER='.(string)$credentials['piwigo_user'],
    'PIWIGO_SYNC_PASSWORD_FILE='.$piwigoPasswordPath,
  );
  file_put_contents($configPath, implode("\n", $lines)."\n", LOCK_EX);
  chmod($configPath, 0600);

  echo "Teste Verbindung mit Plugin-Runtime...\n";
  $test = run_install(array('env', 'PIWIGO_CONFIG='.$configPath, 'bash', $pluginRoot.'/runtime/sync.sh'), true);
  if ($test['exit'] !== 0)
  {
    @unlink($configPath);
    @unlink($storagePath);
    @unlink($dbPasswordPath);
    @unlink($piwigoPasswordPath);
    $detail = trim($test['stderr']) !== '' ? trim($test['stderr']) : trim($test['stdout']);
    fail_install('Runtime-Test fehlgeschlagen'.($detail !== '' ? ': '.$detail : '.'));
  }

  $service = "[Unit]\nDescription=Bratonien NC Connector Sync\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nExecStart=/usr/bin/env bash ".$pluginRoot."/runtime/run-all.sh\n\n";
  $timer = "[Unit]\nDescription=Bratonien NC Connector regelmaessig pruefen\n\n[Timer]\nOnBootSec=3min\nOnUnitActiveSec=1min\nRandomizedDelaySec=15s\nPersistent=true\n\n[Install]\nWantedBy=timers.target\n";
  file_put_contents($servicePath, $service, LOCK_EX);
  file_put_contents($timerPath, $timer, LOCK_EX);
  chmod($servicePath, 0644);
  chmod($timerPath, 0644);

  $config['state_dir'] = $stateDir;
  $config['status_file'] = $statusFile;
  $config['runtime'] = array('mode'=>'plugin-runtime', 'config'=>$configPath);
  $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $now = date('Y-m-d H:i:s');
  if (!$db->query("UPDATE `".$table."` SET enabled=1, takeover_state='active', config_json='".sql_install($db, $json)."', updated='".sql_install($db, $now)."' WHERE id=".$id))
  {
    fail_install('Connector-Status konnte nicht gespeichert werden: '.$db->error);
  }

  run_install(array('systemctl', 'daemon-reload'));
  run_install(array('systemctl', 'enable', '--now', 'bratonien-nc-connector.timer'));

  echo "Aktivierung erfolgreich.\n";
  echo "Verbindung #".$id." verwendet jetzt den nativen Bratonien-Tools-State unter ".$stateDir.".\n";
  echo "Der gemeinsame Connector-Timer verarbeitet alle installierten connection-*.conf Dateien.\n";
}
catch (Throwable $e)
{
  fwrite(STDERR, $e->getMessage()."\n");
  exit(1);
}
