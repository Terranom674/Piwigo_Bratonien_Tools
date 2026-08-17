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

$dir = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-connector-status';
$latest = array(
  'state'=>'idle',
  'message'=>'Noch kein Connector-Status verfügbar.',
  'timestamp'=>0,
  'auth_mode'=>'',
  'api'=>array('state'=>'not_run','message'=>''),
  'fallback'=>array('state'=>'not_run','message'=>''),
  'error_detail'=>'',
);

if (is_dir($dir))
{
  foreach (glob($dir.'/connection-*.json') ?: array() as $file)
  {
    if (!is_readable($file))
    {
      continue;
    }
    $decoded = json_decode((string)@file_get_contents($file), true);
    if (!is_array($decoded))
    {
      continue;
    }
    if ((int)($decoded['timestamp'] ?? 0) >= (int)$latest['timestamp'])
    {
      $latest = array_merge($latest, $decoded);
      $latest['connection_file'] = basename($file);
    }
  }
}

echo json_encode($latest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
