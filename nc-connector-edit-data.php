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
  echo json_encode(array('error'=>'Bratonien Tools ist nicht aktiv.'));
  exit;
}
if (!function_exists('is_admin') || !is_admin())
{
  http_response_code(403);
  echo json_encode(array('error'=>'Administratorrechte erforderlich.'));
  exit;
}

require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector_manage.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector_connection_scope.inc.php');

$id = (int)($_GET['connection_id'] ?? 0);
$connection = bratonien_tools_nc_connector_connection($id, true);
if (!$connection)
{
  http_response_code(404);
  echo json_encode(array('error'=>'Connector-Verbindung wurde nicht gefunden.'));
  exit;
}

$config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
$credentials = array();
try
{
  $credentials = bratonien_tools_nc_connector_scoped_secret($connection);
}
catch (Throwable $e)
{
  $credentials = array();
}

$storages = array();
foreach ((array)($config['storages'] ?? array()) as $storage)
{
  $storages[] = array(
    'storage_id'=>(string)($storage['storage_id'] ?? ''),
    'source_prefix'=>(string)($storage['source_prefix'] ?? ''),
    'local_mount'=>(string)($storage['local_mount'] ?? ''),
  );
}

$payload = array(
  'id'=>(int)$connection['id'],
  'name'=>(string)$connection['name'],
  'adapter'=>(string)$connection['adapter'],
  'enabled'=>(bool)$connection['enabled'],
  'takeover_state'=>(string)$connection['takeover_state'],
  'source_mode'=>(string)($config['source_mode'] ?? ''),
  'legacy'=>array(
    'host'=>(string)($config['host'] ?? ''),
    'port'=>(string)($config['port'] ?? '5432'),
    'database'=>(string)($config['database'] ?? ''),
    'user'=>(string)($config['user'] ?? ''),
    'has_db_password'=>trim((string)($credentials['db_password'] ?? '')) !== '',
    'source_view'=>(string)($config['source_view'] ?? ''),
    'activity_view'=>(string)($config['activity_view'] ?? ''),
    'gallery_root'=>(string)($config['gallery_root'] ?? ''),
    'quiet_seconds'=>(int)($config['quiet_seconds'] ?? 120),
    'max_wait_seconds'=>(int)($config['max_wait_seconds'] ?? 900),
    'full_sync_seconds'=>(int)($config['full_sync_seconds'] ?? 86400),
    'storages'=>$storages,
  ),
  'webdav'=>array(
    'nextcloud_url'=>(string)($config['nextcloud_url'] ?? ''),
    'nextcloud_user'=>(string)($credentials['nextcloud_user'] ?? $config['nextcloud_access_user'] ?? $config['access_user'] ?? ''),
    'has_nextcloud_password'=>(string)($credentials['nextcloud_password'] ?? '') !== '',
    'api_key_id'=>(string)($credentials['api_key_id'] ?? ''),
    'has_api_key_secret'=>trim((string)($credentials['api_key_secret'] ?? '')) !== '',
    'fallback_user'=>(string)($credentials['piwigo_user'] ?? ''),
    'has_fallback_password'=>(string)($credentials['piwigo_password'] ?? '') !== '',
  ),
);

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
