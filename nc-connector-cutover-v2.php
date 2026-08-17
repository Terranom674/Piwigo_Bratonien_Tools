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
  fwrite(STDERR, "Aufruf: php nc-connector-cutover-v2.php <connection-id>\n");
  exit(1);
}

$connectionId = (int)$argv[1];
$piwigoRoot = dirname(__DIR__, 2);
$dbConfig = $piwigoRoot.'/local/config/database.inc.php';
$legacyConfig = '/etc/piwigo-sync/piwigo.conf';
$legacyRuntime = '/opt/piwigo-sync/sync.sh';
$newTimer = 'bratonien-nc-connector.timer';
$newService = 'bratonien-nc-connector.service';
$legacyTimer = 'piwigo-sync.timer';

function fail($message)
{
  throw new RuntimeException($message);
}

function readKeyValueFile($path)
{
  if (!is_readable($path))
  {
    fail('Konfiguration nicht lesbar: '.$path);
  }
  $result = array();
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line)
  {
    $line = trim($line);
    if ($line === '' || $line[0] === '#')
    {
      continue;
    }
    if (!preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $matches))
    {
      continue;
    }
    $value = trim($matches[2]);
    if (strlen($value) >= 2)
    {
      $first = $value[0];
      $last = $value[strlen($value)-1];
      if (($first === '"' && $last === '"') || ($first === "'" && $last === "'"))
      {
        $value = substr($value, 1, -1);
      }
    }
    $result[$matches[1]] = $value;
  }
  return $result;
}

function runCommand(array $command, $allowFailure = false)
{
  $spec = array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
  );
  $process = proc_open($command, $spec, $pipes);
  if (!is_resource($process))
  {
    fail('Prozess konnte nicht gestartet werden: '.implode(' ', $command));
  }
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit = proc_close($process);
  if ($exit !== 0 && !$allowFailure)
  {
    $detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
    fail('Befehl fehlgeschlagen ('.$exit.'): '.implode(' ', $command).($detail !== '' ? "\n".$detail : ''));
  }
  return array('exit'=>$exit, 'stdout'=>(string)$stdout, 'stderr'=>(string)$stderr);
}

function decryptConnectorSecret($blob, $hexKey)
{
  if (!preg_match('/^[a-f0-9]{64}$/', $hexKey))
  {
    fail('Connector-Schluessel ist ungueltig.');
  }
  $outer = base64_decode(trim((string)$blob), true);
  $payload = is_string($outer) ? json_decode($outer, true) : null;
  if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1)
  {
    fail('Connector-Zugangsdaten haben ein unbekanntes Format.');
  }
  $iv = base64_decode((string)($payload['iv'] ?? ''), true);
  $tag = base64_decode((string)($payload['tag'] ?? ''), true);
  $cipher = base64_decode((string)($payload['data'] ?? ''), true);
  $plain = openssl_decrypt($cipher, 'aes-256-gcm', hex2bin($hexKey), OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false || $plain === '')
  {
    fail('Connector-Zugangsdaten konnten nicht entschluesselt werden.');
  }
  return (string)$plain;
}

function sqlEscape(mysqli $db, $value)
{
  return $db->real_escape_string((string)$value);
}

function saveTakeoverResult(mysqli $db, $table, $connectionId, array $config, $state, $enabled)
{
  $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($json))
  {
    fail('Connector-Status konnte nicht serialisiert werden.');
  }
  $now = date('Y-m-d H:i:s');
  $sql = "UPDATE `".$table."` SET takeover_state='".sqlEscape($db, $state)."', enabled=".($enabled ? 1 : 0).", config_json='".sqlEscape($db, $json)."', updated='".sqlEscape($db, $now)."' WHERE id=".(int)$connectionId;
  if (!$db->query($sql))
  {
    fail('Connector-Status konnte nicht gespeichert werden: '.$db->error);
  }
}

$legacyTimerWasEnabled = false;
$newTimerInstalled = false;
$db = null;
$table = '';
$config = array();

try
{
  if (!is_readable($dbConfig))
  {
    fail('Piwigo-Datenbankkonfiguration nicht lesbar: '.$dbConfig);
  }
  if (!is_executable($legacyRuntime))
  {
    fail('Bestehende Sync-Runtime fehlt: '.$legacyRuntime);
  }

  $conf = array();
  $prefixeTable = 'piwigo_';
  require $dbConfig;
  foreach (array('db_host','db_user','db_password','db_base') as $key)
  {
    if (!isset($conf[$key]))
    {
      fail('Piwigo-Datenbankkonfiguration enthaelt '.$key.' nicht.');
    }
  }

  $db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
  if ($db->connect_errno)
  {
    fail('Piwigo-Datenbank nicht erreichbar: '.$db->connect_error);
  }
  $db->set_charset('utf8mb4');

  $table = $prefixeTable.'bratonien_tools_nc_connections';
  $result = $db->query("SELECT id, takeover_state, enabled, config_json, secret_blob FROM `".$table."` WHERE id=".$connectionId." LIMIT 1");
  if (!$result || !$result->num_rows)
  {
    fail('Connector-Verbindung #'.$connectionId.' wurde nicht gefunden.');
  }
  $row = $result->fetch_assoc();
  if ((string)$row['takeover_state'] !== 'ready' || (int)$row['enabled'] !== 0)
  {
    fail('Connector-Verbindung muss im Zustand ready und deaktiviert sein.');
  }
  $config = json_decode((string)$row['config_json'], true);
  if (!is_array($config) || empty($config['verification']['ok']))
  {
    fail('Connector-Verbindung besitzt keine erfolgreiche Verifikation.');
  }

  $keyResult = $db->query("SELECT value FROM `".$prefixeTable."config` WHERE param='bratonien_nc_connector_secret' LIMIT 1");
  if (!$keyResult || !$keyResult->num_rows)
  {
    fail('Connector-Schluessel wurde in Piwigo nicht gefunden.');
  }
  $keyRow = $keyResult->fetch_assoc();
  $dbPassword = decryptConnectorSecret($row['secret_blob'], (string)$keyRow['value']);

  $legacy = readKeyValueFile($legacyConfig);
  $piwigoUser = trim((string)($legacy['PIWIGO_SYNC_USER'] ?? ''));
  $piwigoPasswordFile = trim((string)($legacy['PIWIGO_SYNC_PASSWORD_FILE'] ?? '/etc/piwigo-sync/piwigo-password'));
  if ($piwigoUser === '' || !is_readable($piwigoPasswordFile))
  {
    fail('Legacy-Piwigo-Sync-Zugangsdaten konnten fuer den einmaligen Cutover nicht gelesen werden.');
  }
  $piwigoPassword = trim((string)file_get_contents($piwigoPasswordFile));
  if ($piwigoPassword === '')
  {
    fail('Legacy-Piwigo-Sync-Passwort ist leer.');
  }

  foreach (array('host','port','database','user','source_view','gallery_root','state_dir') as $key)
  {
    if (!isset($config[$key]) || trim((string)$config[$key]) === '')
    {
      fail('Connector-Konfiguration ist unvollstaendig: '.$key.' fehlt.');
    }
  }

  $runtimeDir = '/etc/bratonien-tools/nc-connector';
  if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0700, true))
  {
    fail('Connector-Laufzeitverzeichnis konnte nicht angelegt werden.');
  }
  chmod($runtimeDir, 0700);

  $base = $runtimeDir.'/connection-'.$connectionId;
  $dbPasswordPath = $base.'.db-password';
  $piwigoPasswordPath = $base.'.piwigo-password';
  $storagePath = $base.'.storages.tsv';
  $configPath = $base.'.conf';
  $statusPath = rtrim((string)$config['state_dir'], '/').'/connector-status.json';

  file_put_contents($dbPasswordPath, $dbPassword."\n", LOCK_EX);
  file_put_contents($piwigoPasswordPath, $piwigoPassword."\n", LOCK_EX);
  chmod($dbPasswordPath, 0600);
  chmod($piwigoPasswordPath, 0600);

  $storageLines = array('# storage_id<TAB>source_prefix<TAB>local_mount');
  foreach ((array)($config['storages'] ?? array()) as $storage)
  {
    $storageLines[] = (string)($storage['storage_id'] ?? '')."\t".(string)($storage['source_prefix'] ?? '')."\t".(string)($storage['local_mount'] ?? '');
  }
  file_put_contents($storagePath, implode("\n", $storageLines)."\n", LOCK_EX);
  chmod($storagePath, 0600);

  $piwigoRootConfigured = isset($legacy['PIWIGO_ROOT']) && trim((string)$legacy['PIWIGO_ROOT']) !== '' ? trim((string)$legacy['PIWIGO_ROOT']) : $piwigoRoot;
  $lines = array(
    'PIWIGO_ROOT='.$piwigoRootConfigured,
    'GALLERY_ROOT='.(string)$config['gallery_root'],
    'STATE_DIR='.(string)$config['state_dir'],
    'STATUS_FILE='.$statusPath,
    'NC_DB_HOST='.(string)$config['host'],
    'NC_DB_PORT='.(string)$config['port'],
    'NC_DB_NAME='.(string)$config['database'],
    'NC_DB_USER='.(string)$config['user'],
    'NC_DB_VIEW='.(string)$config['source_view'],
    'NC_DB_PASSWORD_FILE='.$dbPasswordPath,
    'STORAGE_CONFIG='.$storagePath,
    'QUIET_SECONDS='.(int)($config['quiet_seconds'] ?? 120),
    'MAX_WAIT_SECONDS='.(int)($config['max_wait_seconds'] ?? 900),
    'FULL_SYNC_SECONDS='.(int)($config['full_sync_seconds'] ?? 86400),
    'PIWIGO_SYNC_ENABLED=1',
    'PIWIGO_SYNC_USER='.$piwigoUser,
    'PIWIGO_SYNC_PASSWORD_FILE='.$piwigoPasswordPath,
  );
  file_put_contents($configPath, implode("\n", $lines)."\n", LOCK_EX);
  chmod($configPath, 0600);

  $enabledCheck = runCommand(array('systemctl', 'is-enabled', $legacyTimer), true);
  $legacyTimerWasEnabled = $enabledCheck['exit'] === 0;

  echo "Stoppe Legacy-Timer...\n";
  runCommand(array('systemctl', 'stop', $legacyTimer));

  $deadline = time() + 120;
  do
  {
    $active = runCommand(array('systemctl', 'is-active', '--quiet', 'piwigo-sync.service'), true);
    if ($active['exit'] !== 0)
    {
      break;
    }
    if (time() >= $deadline)
    {
      fail('Ein laufender Legacy-Sync wurde nach 120 Sekunden nicht beendet.');
    }
    sleep(2);
  }
  while (true);

  @unlink($statusPath);
  echo "Fuehre ersten Connector-Lauf aus...\n";
  $firstRun = runCommand(array('env', 'PIWIGO_CONFIG='.$configPath, $legacyRuntime), true);
  if ($firstRun['exit'] !== 0)
  {
    $detail = trim($firstRun['stderr']) !== '' ? trim($firstRun['stderr']) : trim($firstRun['stdout']);
    fail('Erster Connector-Lauf ist technisch fehlgeschlagen'.($detail !== '' ? ': '.$detail : '.'));
  }

  $runResult = 'no_changes';
  $statusState = '';
  $statusMessage = '';
  if (is_readable($statusPath))
  {
    $status = json_decode((string)file_get_contents($statusPath), true);
    if (is_array($status))
    {
      $statusState = trim((string)($status['state'] ?? ''));
      $statusMessage = trim((string)($status['message'] ?? ''));
    }

    if ($statusState === 'error')
    {
      fail('Erster Connector-Lauf meldete einen technischen Fehler'.($statusMessage !== '' ? ': '.$statusMessage : '.'));
    }
    if ($statusState === 'ok')
    {
      $runResult = 'changed';
    }
  }

  echo 'Erster Lauf Exit-Code: '.$firstRun['exit']."\n";
  echo 'Connector-Status: '.($statusState !== '' ? $statusState : 'kein Abschlussstatus').($statusMessage !== '' ? ' - '.$statusMessage : '')."\n";
  echo 'Bewertung: '.$runResult."\n";

  $servicePath = '/etc/systemd/system/'.$newService;
  $timerPath = '/etc/systemd/system/'.$newTimer;
  $service = "[Unit]\nDescription=Bratonien NC Connector Sync\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nEnvironment=PIWIGO_CONFIG=".$configPath."\nExecStart=".$legacyRuntime."\n\n";
  $timer = "[Unit]\nDescription=Bratonien NC Connector regelmaessig pruefen\n\n[Timer]\nOnBootSec=3min\nOnUnitActiveSec=1min\nRandomizedDelaySec=15s\nPersistent=true\n\n[Install]\nWantedBy=timers.target\n";
  file_put_contents($servicePath, $service, LOCK_EX);
  file_put_contents($timerPath, $timer, LOCK_EX);
  chmod($servicePath, 0644);
  chmod($timerPath, 0644);
  $newTimerInstalled = true;

  runCommand(array('systemctl', 'daemon-reload'));
  runCommand(array('systemctl', 'disable', $legacyTimer), true);
  runCommand(array('systemctl', 'enable', '--now', $newTimer));

  $config['takeover']['cutover_at'] = date('Y-m-d H:i:s');
  $config['takeover']['legacy_timer_disabled'] = true;
  $config['takeover']['connector_timer'] = $newTimer;
  $config['takeover']['runtime'] = 'legacy-runtime-transition';
  $config['takeover']['first_run'] = array(
    'state' => 'success',
    'result' => $runResult,
    'status_state' => $statusState,
    'status_message' => $statusMessage,
    'checked_at' => date('Y-m-d H:i:s'),
  );
  saveTakeoverResult($db, $table, $connectionId, $config, 'active', true);

  echo "Cutover erfolgreich.\n";
  echo "Erster Connector-Lauf: ".$runResult."\n";
  echo "Legacy-Timer ist deaktiviert; ".$newTimer." ist aktiv.\n";
  echo "Die bisherige Sync-Runtime bleibt fuer diesen Uebergangsschritt noch als Laufzeit erhalten.\n";
}
catch (Throwable $e)
{
  if ($db instanceof mysqli && $table !== '' && $config)
  {
    try
    {
      $config['takeover']['first_run'] = array(
        'state' => 'error',
        'result' => 'error',
        'checked_at' => date('Y-m-d H:i:s'),
        'message' => substr($e->getMessage(), 0, 500),
      );
      saveTakeoverResult($db, $table, $connectionId, $config, 'ready', false);
    }
    catch (Throwable $ignored)
    {
    }
  }

  if ($newTimerInstalled)
  {
    runCommand(array('systemctl', 'disable', '--now', $newTimer), true);
  }
  if ($legacyTimerWasEnabled)
  {
    runCommand(array('systemctl', 'enable', '--now', $legacyTimer), true);
  }
  else
  {
    runCommand(array('systemctl', 'start', $legacyTimer), true);
  }

  fwrite(STDERR, "Cutover fehlgeschlagen: ".$e->getMessage()."\n");
  fwrite(STDERR, "Legacy-Timer wurde wieder aktiviert/gestartet.\n");
  exit(1);
}
