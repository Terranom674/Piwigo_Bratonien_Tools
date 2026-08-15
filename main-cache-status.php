<?php
$piwigo_root = realpath(dirname(__DIR__, 2));
if ($piwigo_root === false)
{
  http_response_code(500);
  exit;
}
define('PHPWG_ROOT_PATH', rtrim($piwigo_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  http_response_code(404);
  echo json_encode(array('state'=>'unavailable','message'=>'Bratonien Tools ist nicht aktiv.'));
  exit;
}
if (!function_exists('is_admin') || !is_admin())
{
  http_response_code(403);
  echo json_encode(array('state'=>'forbidden','message'=>'Administratorrechte erforderlich.'));
  exit;
}

require_once(BRATONIEN_TOOLS_PATH.'tools/image_cache.inc.php');
$file = bratonien_tools_main_cache_status_file();
if (!is_file($file) || !is_readable($file))
{
  echo json_encode(array(
    'state'=>'idle','message'=>'Noch kein manueller Cache-Aufbau gestartet.',
    'total'=>0,'completed'=>0,'generated'=>0,'cached'=>0,'skipped'=>0,'errors'=>0,'current'=>'','updated_at'=>0,
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$raw = @file_get_contents($file);
$data = $raw !== false ? json_decode($raw, true) : null;
if (!is_array($data))
{
  http_response_code(500);
  echo json_encode(array('state'=>'error','message'=>'Cache-Status ist nicht lesbar.'));
  exit;
}

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
