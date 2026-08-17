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

function bratonien_tools_nc_connector_system_status()
{
  $timer = 'bratonien-nc-connector.timer';
  $next_raw = bratonien_tools_nc_connector_systemctl_value(array('show', $timer, '--property=NextElapseUSecRealtime', '--value'));
  $next_timestamp = 0;
  if ($next_raw !== '' && strtolower($next_raw) !== 'n/a')
  {
    $parsed = strtotime($next_raw);
    if ($parsed !== false)
    {
      $next_timestamp = (int)$parsed;
    }
  }

  $active = bratonien_tools_nc_connector_systemctl_value(array('is-active', $timer));
  $enabled = bratonien_tools_nc_connector_systemctl_value(array('is-enabled', $timer));

  return array(
    'timer_name' => $timer,
    'timer_active' => $active === 'active',
    'timer_enabled' => $enabled === 'enabled',
    'next_run_raw' => $next_raw,
    'next_run_timestamp' => $next_timestamp,
    'next_run_label' => $next_timestamp > 0 ? date('d.m.Y H:i:s', $next_timestamp) : ($next_raw !== '' ? $next_raw : 'Nicht verfügbar'),
    'legacy_runtime_exists' => is_dir('/opt/piwigo-sync'),
    'legacy_config_exists' => is_dir('/etc/piwigo-sync'),
    'legacy_service_exists' => is_file('/etc/systemd/system/piwigo-sync.service'),
    'legacy_timer_exists' => is_file('/etc/systemd/system/piwigo-sync.timer'),
  );
}
