<?php
if (PHP_SAPI !== 'cli')
{
  http_response_code(404);
  exit;
}

$piwigo_root = realpath(dirname(__DIR__, 2));
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
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Warmup-Dispatcher/0.9.7.1.8';
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
foreach ($argv as $arg)
{
  if (preg_match('/^--mode=(sync|periodic|manual)$/', $arg, $m)) $mode = $m[1];
  elseif ($arg === '--wait') $wait = true;
}

$settings = bratonien_tools_get_webdav_warmup_settings();
if ($mode !== 'manual' && empty($settings['enabled']))
{
  fwrite(STDOUT, "WebDAV-Cache-Warmup ist deaktiviert.\n");
  exit(0);
}
if (!function_exists('exec'))
{
  fwrite(STDERR, "PHP exec() ist deaktiviert; Warmup kann nicht gestartet werden.\n");
  exit(1);
}

$worker = realpath(BRATONIEN_TOOLS_PATH.'runtime/lib/webdav-cache-warmup.php');
if (!$worker || !is_file($worker))
{
  fwrite(STDERR, "WebDAV-Cache-Warmup-Worker wurde nicht gefunden.\n");
  exit(1);
}

$log = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.log';
$result = 0;
$started = 0;
foreach (bratonien_tools_nc_connector_connections() as $connection)
{
  if (empty($connection['enabled']) || !bratonien_tools_nc_connector_is_webdav($connection)) continue;
  $connection_id = (int)$connection['id'];
  if ($connection_id < 1) continue;

  $base = escapeshellarg(PHP_BINARY).' '.escapeshellarg($worker)
    .' --connection-id='.$connection_id
    .' --mode='.escapeshellarg($mode);

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
      fwrite(STDERR, "Warmup für Verbindung #{$connection_id} konnte nicht gestartet werden.\n");
      $result = 1;
      continue;
    }
    $started++;
    fwrite(STDOUT, "Warmup {$mode} für Verbindung #{$connection_id} gestartet (PID {$pid}).\n");
  }
}

if (!$wait) fwrite(STDOUT, "Gestartete Warmup-Prozesse: {$started}.\n");
exit($result);
