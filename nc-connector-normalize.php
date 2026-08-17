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
  fwrite(STDERR, "Aufruf: php nc-connector-normalize.php <connection-id>\n");
  exit(1);
}

$id = (int)$argv[1];
$pluginRoot = __DIR__;
$piwigoRoot = dirname(__DIR__, 2);
$dbConfig = $piwigoRoot.'/local/config/database.inc.php';
$configPath = '/etc/bratonien-tools/nc-connector/connection-'.$id.'.conf';
$newState = '/var/lib/bratonien-tools/nc-connector/connection-'.$id;
$servicePath = '/etc/systemd/system/bratonien-nc-connector.service';
$timer = 'bratonien-nc-connector.timer';
$service = 'bratonien-nc-connector.service';

function normalize_run(array $command, $allowFailure = false)
{
  $spec = array(0=>array('file','/dev/null','r'), 1=>array('pipe','w'), 2=>array('pipe','w'));
  $process = proc_open($command, $spec, $pipes);
  if (!is_resource($process)) throw new RuntimeException('Prozess konnte nicht gestartet werden.');
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]); fclose($pipes[2]);
  $exit = proc_close($process);
  if ($exit !== 0 && !$allowFailure)
  {
    $detail = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
    throw new RuntimeException('Befehl fehlgeschlagen ('.$exit.'): '.implode(' ', $command).($detail !== '' ? "\n".$detail : ''));
  }
  return array('exit'=>$exit, 'stdout'=>$stdout, 'stderr'=>$stderr);
}

function remove_tree($path)
{
  if (!is_dir($path)) return;
  $items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($items as $item)
  {
    if ($item->isDir()) @rmdir($item->getPathname()); else @unlink($item->getPathname());
  }
  @rmdir($path);
}

try
{
  $conf = array();
  $prefixeTable = 'piwigo_';
  if (!is_readable($dbConfig)) throw new RuntimeException('Piwigo-Datenbankkonfiguration nicht lesbar.');
  require $dbConfig;
  $db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
  if ($db->connect_errno) throw new RuntimeException('Piwigo-Datenbank nicht erreichbar: '.$db->connect_error);
  $db->set_charset('utf8mb4');
  $table = $prefixeTable.'bratonien_tools_nc_connections';
  $result = $db->query("SELECT id, enabled, takeover_state, config_json FROM `".$table."` WHERE id=".$id." LIMIT 1");
  if (!$result || !$result->num_rows) throw new RuntimeException('Connector-Verbindung #'.$id.' wurde nicht gefunden.');
  $row = $result->fetch_assoc();
  if ((int)$row['enabled'] !== 1 || (string)$row['takeover_state'] !== 'active') throw new RuntimeException('Die Verbindung muss aktiv sein.');
  $config = json_decode((string)$row['config_json'], true);
  if (!is_array($config)) throw new RuntimeException('Connector-Konfiguration ist ungueltig.');
  $oldState = rtrim((string)($config['state_dir'] ?? ''), '/');
  if ($oldState === '') throw new RuntimeException('Bisheriger State-Pfad fehlt.');
  if ($oldState === $newState)
  {
    echo "Verbindung #".$id." verwendet bereits den nativen State-Pfad.\n";
    exit(0);
  }
  if (!is_readable($configPath)) throw new RuntimeException('Aktive Runtime-Konfiguration fehlt: '.$configPath);

  echo "Stoppe Connector-Timer...\n";
  normalize_run(array('systemctl','stop',$timer));
  $deadline = time() + 120;
  while (normalize_run(array('systemctl','is-active','--quiet',$service), true)['exit'] === 0)
  {
    if (time() >= $deadline) throw new RuntimeException('Laufender Connector-Lauf wurde nach 120 Sekunden nicht beendet.');
    sleep(2);
  }

  if (!is_dir(dirname($newState)) && !mkdir(dirname($newState), 0750, true)) throw new RuntimeException('Neues State-Wurzelverzeichnis konnte nicht angelegt werden.');
  if (!is_dir($newState) && !mkdir($newState, 0750, true)) throw new RuntimeException('Neues State-Verzeichnis konnte nicht angelegt werden.');
  if (is_dir($oldState)) normalize_run(array('cp','-a',$oldState.'/.',$newState.'/'));
  chmod($newState, 0750);

  $originalConfig = file_get_contents($configPath);
  if (!is_string($originalConfig)) throw new RuntimeException('Runtime-Konfiguration konnte nicht gelesen werden.');
  $newStatus = $newState.'/connector-status.json';
  $updatedConfig = preg_replace('/^STATE_DIR=.*$/m', 'STATE_DIR='.$newState, $originalConfig, 1);
  $updatedConfig = preg_replace('/^STATUS_FILE=.*$/m', 'STATUS_FILE='.$newStatus, $updatedConfig, 1);
  if (!is_string($updatedConfig) || $updatedConfig === $originalConfig) throw new RuntimeException('Runtime-Konfiguration konnte nicht auf nativen State umgestellt werden.');
  file_put_contents($configPath, $updatedConfig, LOCK_EX);
  chmod($configPath, 0600);

  echo "Teste Plugin-Runtime mit neuem State...\n";
  $test = normalize_run(array('env','PIWIGO_CONFIG='.$configPath,'bash',$pluginRoot.'/runtime/sync.sh'), true);
  if ($test['exit'] !== 0)
  {
    file_put_contents($configPath, $originalConfig, LOCK_EX);
    remove_tree($newState);
    normalize_run(array('systemctl','start',$timer), true);
    $detail = trim($test['stderr']) !== '' ? trim($test['stderr']) : trim($test['stdout']);
    throw new RuntimeException('Normalisierung abgebrochen; alter Zustand wiederhergestellt'.($detail !== '' ? ': '.$detail : '.'));
  }

  $serviceContent = "[Unit]\nDescription=Bratonien NC Connector Sync\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nExecStart=/usr/bin/env bash ".$pluginRoot."/runtime/run-all.sh\n\n";
  file_put_contents($servicePath, $serviceContent, LOCK_EX);
  chmod($servicePath, 0644);

  $config['state_dir'] = $newState;
  $config['status_file'] = $newStatus;
  $config['origin'] = 'native';
  $config['runtime'] = array('mode'=>'plugin-runtime', 'config'=>$configPath);
  if (isset($config['takeover']) && is_array($config['takeover'])) $config['takeover']['runtime'] = 'plugin-runtime';
  $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $jsonEsc = $db->real_escape_string((string)$json);
  $nowEsc = $db->real_escape_string(date('Y-m-d H:i:s'));
  if (!$db->query("UPDATE `".$table."` SET config_json='".$jsonEsc."', updated='".$nowEsc."' WHERE id=".$id)) throw new RuntimeException('Connector-Datenbankstatus konnte nicht aktualisiert werden: '.$db->error);

  normalize_run(array('systemctl','daemon-reload'));
  normalize_run(array('systemctl','enable','--now',$timer));

  if ($oldState !== '/var/lib' && $oldState !== '/' && strpos($oldState, '/var/lib/') === 0)
  {
    remove_tree($oldState);
  }

  echo "Normalisierung erfolgreich.\n";
  echo "State: ".$newState."\n";
  echo "Service: gemeinsamer Multi-Connection-Runner aus Bratonien Tools.\n";
}
catch (Throwable $e)
{
  fwrite(STDERR, $e->getMessage()."\n");
  exit(1);
}
