<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_systemctl_value(array $args)
{
  if (!function_exists('proc_open'))
  {
    return '';
  }

  $command = array_merge(array('/usr/bin/systemctl'), $args);
  $spec = array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
  );
  $environment = array_merge($_ENV, array('LC_ALL'=>'C', 'LANG'=>'C'));
  $process = @proc_open($command, $spec, $pipes, null, $environment);
  if (!is_resource($process))
  {
    return '';
  }
  $stdout = stream_get_contents($pipes[1]);
  stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit = proc_close($process);
  return $exit === 0 ? trim((string)$stdout) : '';
}

function bratonien_tools_nc_connector_parse_systemd_time($value)
{
  $value = trim((string)$value);
  if ($value === '' || strtolower($value) === 'n/a')
  {
    return 0;
  }
  $parsed = strtotime($value);
  return $parsed === false ? 0 : (int)$parsed;
}

function bratonien_tools_nc_connector_monotonic_to_timestamp($value)
{
  $value = trim((string)$value);
  if ($value === '' || $value === '0' || strtolower($value) === 'n/a')
  {
    return 0;
  }

  if (preg_match('/^([0-9]+)(?:us)?$/', $value, $matches))
  {
    $next_boot_seconds = ((float)$matches[1]) / 1000000;
  }
  else
  {
    return 0;
  }

  $uptime_raw = @file_get_contents('/proc/uptime');
  if (!is_string($uptime_raw) || !preg_match('/^([0-9]+(?:\.[0-9]+)?)/', trim($uptime_raw), $uptime_match))
  {
    return 0;
  }

  $remaining = $next_boot_seconds - (float)$uptime_match[1];
  if ($remaining < -1)
  {
    return 0;
  }

  return (int)round(time() + max(0, $remaining));
}

function bratonien_tools_nc_connector_next_from_timer_list($timer)
{
  $line = bratonien_tools_nc_connector_systemctl_value(array(
    'list-timers', '--all', '--no-pager', '--no-legend', $timer,
  ));
  if ($line === '')
  {
    return 0;
  }

  $first_line = trim((string)strtok($line, "\n"));
  if (preg_match('/^(\S+\s+\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s+\S+)/', $first_line, $matches))
  {
    $parsed = strtotime($matches[1]);
    return $parsed === false ? 0 : (int)$parsed;
  }

  return 0;
}

function bratonien_tools_nc_connector_connection_last_status(array $connection)
{
  $empty = array(
    'timestamp'=>0,
    'label'=>'Nicht verfügbar',
    'state'=>'',
    'message'=>'',
    'auth_mode'=>'',
    'api_state'=>'',
    'api_message'=>'',
    'fallback_state'=>'',
    'fallback_message'=>'',
    'error_detail'=>'',
  );

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');

  if ($state_dir === '' && !empty($connection['id']))
  {
    $state_dir = '/var/lib/bratonien-tools/nc-connector/connection-'.(int)$connection['id'];
  }

  if ($state_dir === '')
  {
    return $empty;
  }

  $status_path = $state_dir.'/connector-status.json';
  if (!is_readable($status_path))
  {
    return $empty;
  }

  $decoded = json_decode((string)@file_get_contents($status_path), true);
  if (!is_array($decoded))
  {
    return $empty;
  }

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
  $latest = array('timestamp'=>0, 'state'=>'', 'message'=>'', 'auth_mode'=>'', 'api_state'=>'', 'api_message'=>'', 'fallback_state'=>'', 'fallback_message'=>'', 'error_detail'=>'');

  foreach ($connections as $connection)
  {
    if (empty($connection['enabled']) || (string)($connection['takeover_state'] ?? '') !== 'active')
    {
      continue;
    }

    $status = bratonien_tools_nc_connector_connection_last_status($connection);
    if ((int)$status['timestamp'] >= (int)$latest['timestamp'])
    {
      $latest = $status;
    }
  }

  return $latest;
}

function bratonien_tools_nc_connector_system_status(array $connections = array())
{
  $timer = 'bratonien-nc-connector.timer';
  $service = 'bratonien-nc-connector.service';

  $next_realtime_raw = bratonien_tools_nc_connector_systemctl_value(array('show', $timer, '--property=NextElapseUSecRealtime', '--value'));
  $next_timestamp = bratonien_tools_nc_connector_parse_systemd_time($next_realtime_raw);

  if ($next_timestamp <= 0)
  {
    $next_monotonic_raw = bratonien_tools_nc_connector_systemctl_value(array('show', $timer, '--property=NextElapseUSecMonotonic', '--value'));
    $next_timestamp = bratonien_tools_nc_connector_monotonic_to_timestamp($next_monotonic_raw);
  }

  if ($next_timestamp <= 0)
  {
    $next_timestamp = bratonien_tools_nc_connector_next_from_timer_list($timer);
  }

  $active = bratonien_tools_nc_connector_systemctl_value(array('is-active', $timer));
  $enabled = bratonien_tools_nc_connector_systemctl_value(array('is-enabled', $timer));

  $last = bratonien_tools_nc_connector_last_status($connections);
  if ($last['timestamp'] <= 0)
  {
    $last_raw = bratonien_tools_nc_connector_systemctl_value(array('show', $service, '--property=ExecMainExitTimestamp', '--value'));
    $last['timestamp'] = bratonien_tools_nc_connector_parse_systemd_time($last_raw);
  }

  return array(
    'timer_name' => $timer,
    'timer_active' => $active === 'active',
    'timer_enabled' => $enabled === 'enabled',
    'last_run_timestamp' => (int)$last['timestamp'],
    'last_run_label' => $last['timestamp'] > 0 ? date('d.m.Y H:i:s', (int)$last['timestamp']) : 'Nicht verfügbar',
    'last_run_state' => (string)$last['state'],
    'last_run_message' => (string)$last['message'],
    'last_run_auth_mode' => (string)$last['auth_mode'],
    'last_run_api_state' => (string)$last['api_state'],
    'last_run_api_message' => (string)$last['api_message'],
    'last_run_fallback_state' => (string)$last['fallback_state'],
    'last_run_fallback_message' => (string)$last['fallback_message'],
    'last_run_error_detail' => (string)$last['error_detail'],
    'next_run_timestamp' => $next_timestamp,
    'next_run_label' => $next_timestamp > 0 ? date('d.m.Y H:i:s', $next_timestamp) : 'Nicht verfügbar',
    'legacy_runtime_exists' => is_dir('/opt/piwigo-sync'),
    'legacy_config_exists' => is_dir('/etc/piwigo-sync'),
    'legacy_service_exists' => is_file('/etc/systemd/system/piwigo-sync.service'),
    'legacy_timer_exists' => is_file('/etc/systemd/system/piwigo-sync.timer'),
  );
}
