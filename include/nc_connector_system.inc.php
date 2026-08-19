<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_scheduler.inc.php');

function bratonien_tools_nc_connector_connection_last_status(array $connection)
{
  $empty = array(
    'timestamp'=>0,'label'=>'Nicht verfügbar','state'=>'','message'=>'','auth_mode'=>'',
    'api_state'=>'','api_message'=>'','fallback_state'=>'','fallback_message'=>'','error_detail'=>'',
  );

  $connection_id = (int)($connection['id'] ?? 0);
  $candidates = array();
  if ($connection_id > 0)
  {
    $candidates[] = rtrim(PHPWG_ROOT_PATH, '/').'/_data/bratonien-tools/nc-connector-status/connection-'.$connection_id.'.json';
    $candidates[] = rtrim(PHPWG_ROOT_PATH, '/').'/_data/bratonien-tools/nc-connector-state/connection-'.$connection_id.'/connector-status.json';
  }

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
  if ($state_dir !== '') $candidates[] = $state_dir.'/connector-status.json';

  $decoded = null;
  foreach (array_unique($candidates) as $candidate)
  {
    if (!is_readable($candidate)) continue;
    $value = json_decode((string)@file_get_contents($candidate), true);
    if (is_array($value)) { $decoded = $value; break; }
  }
  if (!is_array($decoded)) return $empty;

  $timestamp = (int)($decoded['timestamp'] ?? 0);
  $api = isset($decoded['api']) && is_array($decoded['api']) ? $decoded['api'] : array();
  $fallback = isset($decoded['fallback']) && is_array($decoded['fallback']) ? $decoded['fallback'] : array();
  return array(
    'timestamp'=>$timestamp,
    'label'=>$timestamp > 0 ? date('d.m.Y H:i:s', $timestamp) : 'Nicht verfügbar',
    'state'=>(string)($decoded['state'] ?? ''),
    'message'=>(string)($decoded['message'] ?? ''),
    'auth_mode'=>(string)($decoded['auth_mode'] ?? ''),
    'api_state'=>(string)($api['state'] ?? ''),
    'api_message'=>(string)($api['message'] ?? ''),
    'fallback_state'=>(string)($fallback['state'] ?? ''),
    'fallback_message'=>(string)($fallback['message'] ?? ''),
    'error_detail'=>(string)($decoded['error_detail'] ?? ''),
  );
}

function bratonien_tools_nc_connector_last_status(array $connections)
{
  $latest = array('timestamp'=>0,'state'=>'','message'=>'','auth_mode'=>'','api_state'=>'','api_message'=>'','fallback_state'=>'','fallback_message'=>'','error_detail'=>'');
  foreach ($connections as $connection)
  {
    if (empty($connection['enabled'])) continue;
    $status = bratonien_tools_nc_connector_connection_last_status($connection);
    if ((int)$status['timestamp'] >= (int)$latest['timestamp']) $latest = $status;
  }
  return $latest;
}

function bratonien_tools_nc_connector_system_status(array $connections = array())
{
  $scheduler = bratonien_tools_nc_scheduler_read_state();
  $enabled = !isset($scheduler['enabled']) || !empty($scheduler['enabled']);
  $scheduler_state = (string)($scheduler['state'] ?? '');
  $running = $scheduler_state === 'running';
  $queued = $scheduler_state === 'queued';
  $started = (int)($scheduler['started_at'] ?? 0);
  $next = (int)($scheduler['next_due'] ?? 0);
  $last = bratonien_tools_nc_connector_last_status($connections);

  if ($next > 0)
  {
    $next_label = date('d.m.Y H:i:s', $next).' (beim nächsten Piwigo-Aufruf)';
  }
  else
  {
    $next_label = 'Beim nächsten Piwigo-Aufruf';
  }

  $scheduler_timestamp = (int)($scheduler['timestamp'] ?? 0);
  if ($scheduler_timestamp > (int)$last['timestamp'])
  {
    $last['timestamp'] = $scheduler_timestamp;
    $last['state'] = $scheduler_state;
    $last['message'] = (string)($scheduler['message'] ?? '');
    if ($scheduler_state === 'error')
    {
      $detail = trim((string)($scheduler['stderr'] ?? ''));
      if ($detail === '') $detail = trim((string)($scheduler['stdout'] ?? ''));
      $last['error_detail'] = $detail;
    }
  }
  elseif ((int)$last['timestamp'] <= 0 && !empty($scheduler['finished_at']))
  {
    $last['timestamp'] = (int)$scheduler['finished_at'];
    $last['state'] = $scheduler_state;
    $last['message'] = (string)($scheduler['message'] ?? '');
    if ((string)$last['state'] === 'error')
    {
      $detail = trim((string)($scheduler['stderr'] ?? ''));
      if ($detail === '') $detail = trim((string)($scheduler['stdout'] ?? ''));
      $last['error_detail'] = $detail;
    }
  }

  $current_label = 'Kein Lauf aktiv';
  if ($queued) $current_label = 'Abgleich angefordert';
  if ($running) $current_label = $started > 0 ? 'Läuft seit '.date('d.m.Y H:i:s', $started) : 'Läuft gerade';

  return array(
    'timer_name'=>'Piwigo nativer NC-Scheduler',
    'timer_active'=>$enabled,
    'timer_enabled'=>$enabled,
    'service_active'=>$running || $queued,
    'current_run_timestamp'=>$queued ? (int)($scheduler['queued_at'] ?? 0) : $started,
    'current_run_label'=>$current_label,
    'last_run_timestamp'=>(int)$last['timestamp'],
    'last_run_label'=>(int)$last['timestamp'] > 0 ? date('d.m.Y H:i:s', (int)$last['timestamp']) : 'Nicht verfügbar',
    'last_run_state'=>(string)$last['state'],
    'last_run_message'=>(string)$last['message'],
    'last_run_auth_mode'=>(string)$last['auth_mode'],
    'last_run_api_state'=>(string)$last['api_state'],
    'last_run_api_message'=>(string)$last['api_message'],
    'last_run_fallback_state'=>(string)$last['fallback_state'],
    'last_run_fallback_message'=>(string)$last['fallback_message'],
    'last_run_error_detail'=>(string)$last['error_detail'],
    'next_run_timestamp'=>$next,
    'next_run_label'=>$next_label,
    'legacy_runtime_exists'=>is_dir('/opt/piwigo-sync'),
    'legacy_config_exists'=>is_dir('/etc/piwigo-sync'),
    'legacy_service_exists'=>is_file('/etc/systemd/system/piwigo-sync.service'),
    'legacy_timer_exists'=>is_file('/etc/systemd/system/piwigo-sync.timer'),
  );
}
