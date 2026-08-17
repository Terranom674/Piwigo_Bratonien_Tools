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
  $process = @proc_open($command, $spec, $pipes);
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

function bratonien_tools_nc_connector_monotonic_to_timestamp($microseconds)
{
  if (!preg_match('/^([0-9]+)(?:us)?$/', trim((string)$microseconds), $matches))
  {
    return 0;
  }

  $uptime_raw = @file_get_contents('/proc/uptime');
  if (!is_string($uptime_raw) || !preg_match('/^([0-9]+(?:\.[0-9]+)?)/', trim($uptime_raw), $uptime_match))
  {
    return 0;
  }

  $next_boot_seconds = ((float)$matches[1]) / 1000000;
  $uptime_seconds = (float)$uptime_match[1];
  $remaining = $next_boot_seconds - $uptime_seconds;
  if ($remaining < -1)
  {
    return 0;
  }

  return (int)round(time() + max(0, $remaining));
}

function bratonien_tools_nc_connector_last_status(array $connections)
{
  foreach ($connections as $connection)
  {
    if (empty($connection['enabled']) || (string)($connection['takeover_state'] ?? '') !== 'active')
    {
      continue;
    }

    $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
    $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
    if ($state_dir === '')
    {
      continue;
    }

    $status_path = $state_dir.'/connector-status.json';
    if (!is_readable($status_path))
    {
      continue;
    }

    $decoded = json_decode((string)@file_get_contents($status_path), true);
    if (!is_array($decoded))
    {
      continue;
    }

    $timestamp = (int)($decoded['timestamp'] ?? 0);
    return array(
      'timestamp' => $timestamp,
      'state' => (string)($decoded['state'] ?? ''),
      'message' => (string)($decoded['message'] ?? ''),
    );
  }

  return array('timestamp'=>0, 'state'=>'', 'message'=>'');
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
    'next_run_timestamp' => $next_timestamp,
    'next_run_label' => $next_timestamp > 0 ? date('d.m.Y H:i:s', $next_timestamp) : 'Nicht verfügbar',
    'legacy_runtime_exists' => is_dir('/opt/piwigo-sync'),
    'legacy_config_exists' => is_dir('/etc/piwigo-sync'),
    'legacy_service_exists' => is_file('/etc/systemd/system/piwigo-sync.service'),
    'legacy_timer_exists' => is_file('/etc/systemd/system/piwigo-sync.timer'),
  );
}
