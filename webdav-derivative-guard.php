<?php
define('PHPWG_ROOT_PATH', '../../');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  define('BRATONIEN_TOOLS_ID', basename(__DIR__));
  define('BRATONIEN_TOOLS_PATH', PHPWG_ROOT_PATH.'plugins/'.BRATONIEN_TOOLS_ID.'/');
}
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_materialize_runtime.inc.php');

$image_id = (int)($_GET['id'] ?? 0);
if ($image_id < 1)
{
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Ungueltige Derivatanforderung.';
  exit;
}

$source = bratonien_tools_webdav_materialize_source_info($image_id);
if (!$source)
{
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'WebDAV-Bildquelle nicht gefunden.';
  exit;
}

$state_dir = rtrim((string)($source['state_dir'] ?? ''), '/');
if ($state_dir === '')
{
  http_response_code(503);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Connector-State-Verzeichnis fehlt.';
  exit;
}

$lock = @fopen($state_dir.'/webdav-sync.lock', 'c');
if (!$lock)
{
  http_response_code(503);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Connector-Sync-Lock konnte nicht geoeffnet werden.';
  exit;
}

// On-Demand bleibt parallel zu anderen Bildabrufen moeglich, verhindert aber
// fuer die Dauer dieses einzelnen Derivataufrufs einen exklusiven Connector-
// Tree-Swap. Der bestehende Bild-Lock im eigentlichen Endpoint koordiniert
// weiterhin On-Demand und Warmup fuer dieselbe Bild-ID.
if (!@flock($lock, LOCK_SH))
{
  fclose($lock);
  http_response_code(503);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Connector-Sync-Schutz konnte nicht gesetzt werden.';
  exit;
}

try
{
  include BRATONIEN_TOOLS_PATH.'webdav-derivative.php';
}
finally
{
  @flock($lock, LOCK_UN);
  fclose($lock);
}
