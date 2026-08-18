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

require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector_wizard.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector_wizard_db_bridge.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector_wizard_user_scope.inc.php');

try
{
  $state = bratonien_tools_nc_wizard_state();
  $path = trim((string)($_GET['path'] ?? ''), '/');
  $listing = bratonien_tools_nc_wizard_webdav_list($state, $path);
  echo json_encode(array(
    'state'=>'ok',
    'ready'=>!empty($state['directory_selection_ready']),
    'username'=>(string)$state['username'],
    'current'=>$listing['current'],
    'parent'=>$listing['parent'],
    'children'=>$listing['children'],
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
catch (Throwable $e)
{
  http_response_code(500);
  echo json_encode(array('state'=>'error','message'=>$e->getMessage()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
