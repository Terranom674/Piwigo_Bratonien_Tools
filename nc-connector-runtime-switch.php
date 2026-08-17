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
  fwrite(STDERR, "Aufruf: php nc-connector-runtime-switch.php <connection-id>\n");
  exit(1);
}

$connectionId = (int)$argv[1];
$piwigoRoot = dirname(__DIR__, 2);
$dbConfig = $piwigoRoot.'/local/config/database.inc.php';
$pluginRuntime = __DIR__.'/runtime/sync.sh';
$configPath = '/etc/bratonien-tools/nc-connector/connection-'.$connectionId.'.conf';
$serviceName = 'bratonien-nc-connector.service';
$timerName = 'bratonien-nc-connector.timer';
$servicePath = '/etc/systemd/system/'.$serviceName;

function failRuntimeSwitch($message)
{
  throw new RuntimeException($message);
}

function runRuntimeSwitch(array $command, $allowFailure = false)
{
  $spec = array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
  );
  $process = proc_open($command, $spec, $pipes);
  if (!is_resource($process))
  {
    failRuntimeSwitch('Prozess konnte nicht gestartet werden: '.implode(' ', $command));
  }
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit = proc_close($process);
  if ($exit !== 0 && !$allowFailure)
  {
    $detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
    failRuntimeSwitch('Befehl fehlgeschlagen ('.$exit.'): '.implode(' ', $command).($detail !== '' ? "\n".$detail : ''));
  }
  return array('exit'=>$exit, 'stdout'=>(string)$stdout, 'stderr'=>(string)$stderr);
}

function parseRuntimeConfig($path)
{
  if (!is_readable($path))
  {
    failRuntimeSwitch('Connector-Konfiguration nicht lesbar: '.$path);
  }
  $result = array();
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line)
  {
    $line = trim($line);
    if ($line === '' || $line[0] === '#')
    {
      continue;
    }
    if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $matches))
    {
      $result[$matches[1]] = trim($matches[2]);
    }
  }
  return $result;
}

function dbEscapeRuntime(mysqli $db, $value)
{
  return $db->real_escape_string((string)$value);
}

$oldService = null;
$db = null;
$table = '';
$config = array();
$timerStopped = false;

try
{
  if (!is_file($pluginRuntime) || !is_readable($pluginRuntime))
  {
    failRuntimeSwitch('Plugin-Runtime fehlt: '.$pluginRuntime);
  }
  if (!is_readable($dbConfig))
  {
    failRuntimeSwitch('Piwigo-Datenbankkonfiguration nicht lesbar.');
  }
  if (!is_readable($servicePath))
  {
    failRuntimeSwitch('Bestehender Connector-Service wurde nicht gefunden: '.$servicePath);
  }

  $conf = array();
  $prefixeTable = 'piwigo_';
  require $dbConfig;
  $db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
  if ($db->connect_errno)
  {
    failRuntimeSwitch('Piwigo-Datenbank nicht erreichbar: '.$db->connect_error);
  }
  $db->set_charset('utf8mb4');
  $table = $prefixeTable.'bratonien_tools_nc_connections';
  $result = $db->query("SELECT takeover_state, enabled, config_json FROM `".$table."` WHERE id=".$connectionId." LIMIT 1");
  if (!$result || !$result->num_rows)
  {
    failRuntimeSwitch('Connector-Verbindung #'.$connectionId.' wurde nicht gefunden.');
  }
  $row = $result->fetch_assoc();
  if ((string)$row['takeover_state'] !== 'active' || (int)$row['enabled'] !== 1)
  {
    failRuntimeSwitch('Runtime-Switch ist nur fuer eine aktive Connector-Verbindung erlaubt.');
  }
  $config = json_decode((string)$row['config_json'], true);
  if (!is_array($config))
  {
    failRuntimeSwitch('Connector-Konfiguration ist ungueltig.');
  }

  $runtimeConfig = parseRuntimeConfig($configPath);
  $statusPath = isset($runtimeConfig['STATUS_FILE']) ? (string)$runtimeConfig['STATUS_FILE'] : '';
  if ($statusPath === '')
  {
    failRuntimeSwitch('STATUS_FILE fehlt in der Connector-Konfiguration.');
  }

  $oldService = file_get_contents($servicePath);
  if (!is_string($oldService) || $oldService === '')
  {
    failRuntimeSwitch('Bestehende Service-Definition konnte nicht gesichert werden.');
  }

  echo "Stoppe Connector-Timer fuer den Runtime-Test...\n";
  runRuntimeSwitch(array('systemctl', 'stop', $timerName));
  $timerStopped = true;

  $deadline = time() + 120;
  do
  {
    $active = runRuntimeSwitch(array('systemctl', 'is-active', '--quiet', $serviceName), true);
    if ($active['exit'] !== 0)
    {
      break;
    }
    if (time() >= $deadline)
    {
      failRuntimeSwitch('Ein laufender Connector-Sync wurde nach 120 Sekunden nicht beendet.');
    }
    sleep(2);
  }
  while (true);

  @unlink($statusPath);
  echo "Teste Plugin-eigene Sync-Runtime...\n";
  $test = runRuntimeSwitch(array('env', 'PIWIGO_CONFIG='.$configPath, '/bin/bash', $pluginRuntime), true);
  if ($test['exit'] !== 0)
  {
    $detail = trim($test['stderr']) !== '' ? trim($test['stderr']) : trim($test['stdout']);
    failRuntimeSwitch('Plugin-Runtime-Test fehlgeschlagen'.($detail !== '' ? ': '.$detail : '.'));
  }

  $status = is_readable($statusPath) ? json_decode((string)file_get_contents($statusPath), true) : null;
  $state = is_array($status) ? trim((string)($status['state'] ?? '')) : '';
  $message = is_array($status) ? trim((string)($status['message'] ?? '')) : '';
  if ($state === 'error')
  {
    failRuntimeSwitch('Plugin-Runtime meldete einen Fehler'.($message !== '' ? ': '.$message : '.'));
  }

  echo 'Runtime-Test erfolgreich: '.($message !== '' ? $message : 'Exit-Code 0')."\n";

  $service = "[Unit]\nDescription=Bratonien NC Connector Sync\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nEnvironment=PIWIGO_CONFIG=".$configPath."\nExecStart=/bin/bash ".$pluginRuntime."\n\n";
  if (file_put_contents($servicePath, $service, LOCK_EX) === false)
  {
    failRuntimeSwitch('Neue Service-Definition konnte nicht geschrieben werden.');
  }
  chmod($servicePath, 0644);
  runRuntimeSwitch(array('systemctl', 'daemon-reload'));
  runRuntimeSwitch(array('systemctl', 'enable', '--now', $timerName));
  $timerStopped = false;

  $config['takeover']['runtime'] = 'plugin-runtime';
  $config['takeover']['runtime_switched_at'] = date('Y-m-d H:i:s');
  $config['takeover']['runtime_path'] = $pluginRuntime;
  $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($json))
  {
    failRuntimeSwitch('Connector-Status konnte nicht serialisiert werden.');
  }
  $now = date('Y-m-d H:i:s');
  $sql = "UPDATE `".$table."` SET config_json='".dbEscapeRuntime($db, $json)."', updated='".dbEscapeRuntime($db, $now)."' WHERE id=".$connectionId;
  if (!$db->query($sql))
  {
    failRuntimeSwitch('Connector-Status konnte nicht aktualisiert werden: '.$db->error);
  }

  echo "Runtime-Switch erfolgreich.\n";
  echo "Der Connector verwendet jetzt ausschliesslich die Plugin-eigene Sync-Runtime.\n";
}
catch (Throwable $e)
{
  if (is_string($oldService) && $oldService !== '')
  {
    @file_put_contents($servicePath, $oldService, LOCK_EX);
    runRuntimeSwitch(array('systemctl', 'daemon-reload'), true);
  }
  if ($timerStopped)
  {
    runRuntimeSwitch(array('systemctl', 'enable', '--now', $timerName), true);
  }
  fwrite(STDERR, "Runtime-Switch fehlgeschlagen: ".$e->getMessage()."\n");
  fwrite(STDERR, "Die bisherige Connector-Runtime wurde beibehalten/wiederhergestellt.\n");
  exit(1);
}
