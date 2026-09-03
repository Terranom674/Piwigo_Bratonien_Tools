<?php
if (PHP_SAPI !== 'cli')
{
  http_response_code(404);
  exit;
}

$piwigo_root = realpath(dirname(__DIR__, 3));
if ($piwigo_root === false)
{
  fwrite(STDERR, "Piwigo-Root wurde nicht gefunden.\n");
  exit(1);
}
define('PHPWG_ROOT_PATH', rtrim($piwigo_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/webdav-warmup-dispatch.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Worker-Dispatcher/0.9.7.1.17';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
if (!defined('BRATONIEN_TOOLS_PATH'))
{
  fwrite(STDERR, "Bratonien Tools ist nicht aktiv.\n");
  exit(1);
}
require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_warmup_settings.inc.php');

$mode = 'periodic';
$wait = false;
$connection_filter = 0;
foreach ($argv as $arg)
{
  if (preg_match('/^--mode=(sync|periodic|manual|rebuild)$/', $arg, $m)) $mode = $m[1];
  elseif (preg_match('/^--connection-id=(\d+)$/', $arg, $m)) $connection_filter = (int)$m[1];
  elseif ($arg === '--wait') $wait = true;
}

$settings = bratonien_tools_get_webdav_warmup_settings();
if (!in_array($mode, array('manual','rebuild'), true) && empty($settings['enabled']))
{
  fwrite(STDOUT, "WebDAV-Worker ist deaktiviert.\n");
  exit(0);
}
if (!function_exists('exec'))
{
  fwrite(STDERR, "PHP exec() ist deaktiviert; Worker kann nicht gestartet werden.\n");
  exit(1);
}

$worker = realpath(BRATONIEN_TOOLS_PATH.'runtime/lib/webdav-cache-warmup.php');
$priority_waiter = realpath(BRATONIEN_TOOLS_PATH.'runtime/lib/run-webdav-priority-wait.sh');
if (!$worker || !is_file($worker))
{
  fwrite(STDERR, "WebDAV-Worker wurde nicht gefunden.\n");
  exit(1);
}
if (!$priority_waiter || !is_file($priority_waiter))
{
  fwrite(STDERR, "WebDAV-Prioritäts-Warter wurde nicht gefunden.\n");
  exit(1);
}

$log = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.log';
$result = 0;
$started = 0;
$matched = 0;
foreach (bratonien_tools_nc_connector_connections() as $connection)
{
  if (empty($connection['enabled']) || !bratonien_tools_nc_connector_is_webdav($connection)) continue;
  $connection_id = (int)$connection['id'];
  if ($connection_id < 1) continue;
  if ($connection_filter > 0 && $connection_id !== $connection_filter) continue;
  $matched++;

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
  if ($state_dir === '')
  {
    fwrite(STDERR, "Worker für Verbindung #{$connection_id} übersprungen: Connector-State-Verzeichnis fehlt.\n");
    $result = 1;
    continue;
  }

  if ($mode === 'sync')
  {
    if (!is_dir($state_dir) && !@mkdir($state_dir, 0750, true) && !is_dir($state_dir))
    {
      fwrite(STDERR, "Worker-Priorität für Verbindung #{$connection_id} konnte nicht signalisiert werden: State-Verzeichnis fehlt.\n");
      $result = 1;
      continue;
    }
    $priority_file = $state_dir.'/webdav-cache-warmup-priority-sync';
    $priority_payload = json_encode(array(
      'connection_id'=>$connection_id,
      'requested_at'=>time(),
      'reason'=>'connector-sync-complete',
    ), JSON_UNESCAPED_SLASHES);
    if (!is_string($priority_payload) || @file_put_contents($priority_file, $priority_payload."\n", LOCK_EX) === false)
    {
      fwrite(STDERR, "Worker-Priorität für Verbindung #{$connection_id} konnte nicht geschrieben werden.\n");
      $result = 1;
      continue;
    }
    @chmod($priority_file, 0664);
  }

  $worker_command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker)
    .' --connection-id='.$connection_id
    .' --mode='.escapeshellarg($mode);
  $base = $mode === 'sync'
    ? escapeshellarg('/bin/bash').' '.escapeshellarg($priority_waiter).' '.escapeshellarg($state_dir).' '.$worker_command
    : $worker_command;

  if ($wait)
  {
    $output = array();
    $exit = 1;
    @exec($base.' 2>&1', $output, $exit);
    foreach ($output as $line) fwrite(STDOUT, $line."\n");
    if ($exit !== 0) $result = $exit;
  }
  else
  {
    $command = 'nohup '.$base.' >> '.escapeshellarg($log).' 2>&1 < /dev/null & echo $!';
    $output = array();
    $exit = 1;
    @exec($command, $output, $exit);
    $pid = isset($output[0]) ? (int)$output[0] : 0;
    if ($exit !== 0 || $pid <= 0)
    {
      fwrite(STDERR, "Worker für Verbindung #{$connection_id} konnte nicht gestartet werden.\n");
      $result = 1;
      continue;
    }
    $started++;
    fwrite(STDOUT, "WebDAV-Worker {$mode} für Verbindung #{$connection_id} gestartet (PID {$pid}).\n");
  }
}

if ($connection_filter > 0 && $matched === 0)
{
  fwrite(STDERR, "WebDAV-Verbindung #{$connection_filter} wurde nicht als aktive Connector-Verbindung gefunden.\n");
  exit(1);
}
if (!$wait) fwrite(STDOUT, "Gestartete WebDAV-Worker: {$started}.\n");
exit($result);
