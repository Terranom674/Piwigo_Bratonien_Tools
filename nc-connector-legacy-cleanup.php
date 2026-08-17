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
  fwrite(STDERR, "Aufruf: php nc-connector-legacy-cleanup.php <connection-id>\n");
  exit(1);
}

$connectionId = (int)$argv[1];
$piwigoRoot = dirname(__DIR__, 2);
$dbConfig = $piwigoRoot.'/local/config/database.inc.php';
$pluginRuntime = __DIR__.'/runtime/sync.sh';
$configPath = '/etc/bratonien-tools/nc-connector/connection-'.$connectionId.'.conf';
$connectorService = '/etc/systemd/system/bratonien-nc-connector.service';
$connectorTimer = 'bratonien-nc-connector.timer';
$legacyServiceName = 'piwigo-sync.service';
$legacyTimerName = 'piwigo-sync.timer';
$legacyServicePath = '/etc/systemd/system/'.$legacyServiceName;
$legacyTimerPath = '/etc/systemd/system/'.$legacyTimerName;

function cleanupFail($message)
{
  throw new RuntimeException($message);
}

function cleanupRun(array $command, $allowFailure = false)
{
  $spec = array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
  );
  $process = proc_open($command, $spec, $pipes);
  if (!is_resource($process))
  {
    cleanupFail('Prozess konnte nicht gestartet werden: '.implode(' ', $command));
  }
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit = proc_close($process);
  if ($exit !== 0 && !$allowFailure)
  {
    $detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
    cleanupFail('Befehl fehlgeschlagen ('.$exit.'): '.implode(' ', $command).($detail !== '' ? "\n".$detail : ''));
  }
  return array('exit'=>$exit, 'stdout'=>(string)$stdout, 'stderr'=>(string)$stderr);
}

function cleanupRemoveTree($path)
{
  if (!file_exists($path) && !is_link($path))
  {
    return;
  }
  if (is_link($path) || is_file($path))
  {
    if (!@unlink($path))
    {
      cleanupFail('Datei konnte nicht entfernt werden: '.$path);
    }
    return;
  }
  $items = scandir($path);
  if (!is_array($items))
  {
    cleanupFail('Verzeichnis konnte nicht gelesen werden: '.$path);
  }
  foreach ($items as $item)
  {
    if ($item === '.' || $item === '..')
    {
      continue;
    }
    cleanupRemoveTree($path.'/'.$item);
  }
  if (!@rmdir($path))
  {
    cleanupFail('Verzeichnis konnte nicht entfernt werden: '.$path);
  }
}

try
{
  foreach (array($dbConfig, $configPath, $connectorService, $pluginRuntime) as $required)
  {
    if (!is_readable($required))
    {
      cleanupFail('Erforderliche Connector-Datei fehlt oder ist nicht lesbar: '.$required);
    }
  }

  $serviceDefinition = (string)file_get_contents($connectorService);
  if (strpos($serviceDefinition, $pluginRuntime) === false)
  {
    cleanupFail('Der aktive Connector-Service verwendet noch nicht die Plugin-eigene Runtime. Legacy-Bestand wird nicht entfernt.');
  }

  $conf = array();
  $prefixeTable = 'piwigo_';
  require $dbConfig;
  $db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
  if ($db->connect_errno)
  {
    cleanupFail('Piwigo-Datenbank nicht erreichbar: '.$db->connect_error);
  }
  $db->set_charset('utf8mb4');
  $table = $prefixeTable.'bratonien_tools_nc_connections';
  $result = $db->query("SELECT takeover_state, enabled, config_json FROM `".$table."` WHERE id=".$connectionId." LIMIT 1");
  if (!$result || !$result->num_rows)
  {
    cleanupFail('Connector-Verbindung #'.$connectionId.' wurde nicht gefunden.');
  }
  $row = $result->fetch_assoc();
  $config = json_decode((string)$row['config_json'], true);
  if ((string)$row['takeover_state'] !== 'active' || (int)$row['enabled'] !== 1 || !is_array($config))
  {
    cleanupFail('Legacy-Cleanup ist nur fuer eine aktive Connector-Verbindung erlaubt.');
  }
  if (($config['takeover']['runtime'] ?? '') !== 'plugin-runtime')
  {
    cleanupFail('Connector-Status bestaetigt die Plugin-Runtime noch nicht. Legacy-Bestand wird nicht entfernt.');
  }

  $active = cleanupRun(array('systemctl', 'is-active', $connectorTimer), true);
  if ($active['exit'] !== 0)
  {
    cleanupFail('Der Connector-Timer ist nicht aktiv. Vor dem Cleanup muss der produktive Connector laufen.');
  }

  echo "Deaktiviere verbliebene Legacy-Units...\n";
  cleanupRun(array('systemctl', 'disable', '--now', $legacyTimerName), true);
  cleanupRun(array('systemctl', 'stop', $legacyServiceName), true);

  echo "Entferne alte systemd-Units...\n";
  foreach (array($legacyTimerPath, $legacyServicePath) as $path)
  {
    if (is_file($path) || is_link($path))
    {
      @unlink($path);
    }
  }

  echo "Entferne /opt/piwigo-sync...\n";
  cleanupRemoveTree('/opt/piwigo-sync');

  echo "Entferne /etc/piwigo-sync...\n";
  cleanupRemoveTree('/etc/piwigo-sync');

  cleanupRun(array('systemctl', 'daemon-reload'));
  cleanupRun(array('systemctl', 'reset-failed', $legacyServiceName), true);

  $config['takeover']['legacy_cleaned_at'] = date('Y-m-d H:i:s');
  $config['takeover']['legacy_cleanup'] = true;
  $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (is_string($json))
  {
    $now = date('Y-m-d H:i:s');
    $escapedJson = $db->real_escape_string($json);
    $escapedNow = $db->real_escape_string($now);
    $db->query("UPDATE `".$table."` SET config_json='".$escapedJson."', updated='".$escapedNow."' WHERE id=".$connectionId);
  }

  echo "Legacy-Cleanup erfolgreich.\n";
  echo "Entfernt: /opt/piwigo-sync, /etc/piwigo-sync sowie piwigo-sync.service/timer.\n";
  echo "/var/lib/piwigo-sync bleibt bestehen, weil dort der aktive Connector seinen Laufzeitstatus, Name-Map und Activity-State fuehrt.\n";
}
catch (Throwable $e)
{
  fwrite(STDERR, "Legacy-Cleanup abgebrochen: ".$e->getMessage()."\n");
  exit(1);
}
