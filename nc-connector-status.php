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

$requested_connection_id = isset($_GET['connection_id']) ? max(0, (int)$_GET['connection_id']) : 0;
$dir = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-connector-status';
$latest = array(
  'state'=>'idle',
  'message'=>'Noch kein Connector-Status verfügbar.',
  'timestamp'=>0,
  'last_run_label'=>'Nicht verfügbar',
  'next_run_timestamp'=>0,
  'next_run_label'=>'Nicht verfügbar',
  'auth_mode'=>'',
  'api'=>array('state'=>'not_run','message'=>''),
  'fallback'=>array('state'=>'not_run','message'=>''),
  'error_detail'=>'',
  'route'=>'',
  'route_label'=>'Noch kein Datenweg erfasst',
  'route_timestamp'=>0,
  'route_time_label'=>'Nicht verfügbar',
  'route_detail'=>'',
  'connection_id'=>$requested_connection_id,
);

if (is_dir($dir))
{
  $files = $requested_connection_id > 0
    ? array($dir.'/connection-'.$requested_connection_id.'.json')
    : (glob($dir.'/connection-*.json') ?: array());

  foreach ($files as $file)
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

  if ($requested_connection_id === 0)
  {
    $route_file = $dir.'/route-status.json';
    if (is_readable($route_file))
    {
      $route = json_decode((string)@file_get_contents($route_file), true);
      if (is_array($route))
      {
        $route_name = (string)($route['route'] ?? '');
        $route_timestamp = (int)($route['timestamp'] ?? 0);
        $route_label = (string)($route['label'] ?? '');

        if ($route_name === 'webdav')
        {
          $route_label = 'WEBDAV PRIMÄR';
        }
        elseif ($route_name === 'legacy_fallback')
        {
          $route_label = 'LEGACY-FALLBACK AKTIV';
        }
        elseif ($route_name === 'failed')
        {
          $route_label = 'FEHLER - KEIN ERFOLGREICHER DATENWEG';
        }
        elseif ($route_label === '')
        {
          $route_label = 'UNBEKANNTER DATENWEG';
        }

        $route_detail = trim((string)($route['detail'] ?? ''));
        $latest['route'] = $route_name;
        $latest['route_label'] = $route_label;
        $latest['route_timestamp'] = $route_timestamp;
        $latest['route_time_label'] = $route_timestamp > 0 ? date('d.m.Y H:i:s', $route_timestamp) : 'Nicht verfügbar';
        $latest['route_detail'] = $route_detail;

        $base_message = trim((string)($latest['message'] ?? ''));
        $message_parts = array($route_label);
        if ($route_name !== 'webdav' && $route_detail !== '')
        {
          $message_parts[] = $route_detail;
        }
        if ($base_message !== '')
        {
          $message_parts[] = $base_message;
        }
        $latest['message'] = implode(' · ', $message_parts);
      }
    }
  }
}

$system_file = BRATONIEN_TOOLS_PATH.'include/nc_connector_system.inc.php';
if (is_readable($system_file))
{
  include_once($system_file);
  if (function_exists('bratonien_tools_nc_connector_system_status'))
  {
    $system = bratonien_tools_nc_connector_system_status(array());
    $latest['next_run_timestamp'] = (int)($system['next_run_timestamp'] ?? 0);
    $latest['next_run_label'] = (string)($system['next_run_label'] ?? 'Nicht verfügbar');
  }
}

$scheduler_file = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-connector-scheduler/state.json';
if ($requested_connection_id > 0 && is_readable($scheduler_file))
{
  $scheduler = json_decode((string)@file_get_contents($scheduler_file), true);
  if (
    is_array($scheduler)
    && (int)($scheduler['connection_id'] ?? 0) === $requested_connection_id
    && in_array((string)($scheduler['state'] ?? ''), array('queued','running'), true)
    && (int)($scheduler['timestamp'] ?? 0) >= (int)($latest['timestamp'] ?? 0)
  )
  {
    $latest['state'] = (string)$scheduler['state'];
    $latest['message'] = (string)($scheduler['message'] ?? 'NC-Abgleich läuft.');
    $latest['timestamp'] = (int)($scheduler['timestamp'] ?? time());
    $latest['error_detail'] = '';
  }
}

if ((int)$latest['timestamp'] > 0)
{
  $latest['last_run_label'] = date('d.m.Y H:i:s', (int)$latest['timestamp']);
}

echo json_encode($latest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
