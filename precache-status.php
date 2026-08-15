<?php
define('PHPWG_ROOT_PATH', '../../');
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

$status_file = bratonien_tools_precache_status_file();
if (!is_file($status_file) || !is_readable($status_file))
{
  echo json_encode(array(
    'state'=>'idle',
    'message'=>'Noch kein Wasserzeichen-Precache ausgeführt.',
    'total'=>0,
    'completed'=>0,
    'generated'=>0,
    'cached'=>0,
    'skipped'=>0,
    'errors'=>0,
    'current'=>'',
    'updated_at'=>0,
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$data = @file_get_contents($status_file);
$decoded = $data !== false ? json_decode($data, true) : null;
if (!is_array($decoded))
{
  http_response_code(500);
  echo json_encode(array('state'=>'error','message'=>'Precache-Status ist nicht lesbar.'));
  exit;
}

echo json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
