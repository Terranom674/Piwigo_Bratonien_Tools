<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Read the existing piwigo-sync configuration without executing it.
 *
 * Phase 1 of the NC Connector is intentionally read-only: it observes the
 * installation created by Proxmox_Scripts but does not change credentials,
 * PostgreSQL views, systemd units, sync state or the gallery tree.
 */
function bratonien_tools_nc_connector_read_config($path)
{
  $config = array();

  if (!is_readable($path))
  {
    return $config;
  }

  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($lines))
  {
    return $config;
  }

  foreach ($lines as $line)
  {
    $line = trim($line);
    if ($line === '' || $line[0] === '#')
    {
      continue;
    }

    if (!preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $matches))
    {
      continue;
    }

    $value = trim($matches[2]);
    $length = strlen($value);
    if ($length >= 2)
    {
      $first = $value[0];
      $last = $value[$length - 1];
      if (($first === '"' && $last === '"') || ($first === "'" && $last === "'"))
      {
        $value = substr($value, 1, -1);
      }
    }

    $config[$matches[1]] = $value;
  }

  return $config;
}

function bratonien_tools_nc_connector_status()
{
  $config_path = '/etc/piwigo-sync/piwigo.conf';
  $config_exists = is_file($config_path);
  $config_readable = is_readable($config_path);
  $config = bratonien_tools_nc_connector_read_config($config_path);

  $required = array('NC_DB_HOST', 'NC_DB_PORT', 'NC_DB_NAME', 'NC_DB_USER', 'NC_DB_VIEW');
  $missing = array();
  foreach ($required as $key)
  {
    if (!isset($config[$key]) || trim((string)$config[$key]) === '')
    {
      $missing[] = $key;
    }
  }

  $password_file = isset($config['NC_DB_PASSWORD_FILE']) ? (string)$config['NC_DB_PASSWORD_FILE'] : '';
  $status_file = isset($config['STATUS_FILE']) && $config['STATUS_FILE'] !== ''
    ? (string)$config['STATUS_FILE']
    : '/var/lib/piwigo-sync/status.json';

  $sync_status = array(
    'available' => false,
    'state' => '',
    'message' => '',
    'timestamp' => 0,
    'time_label' => '',
  );

  if (is_readable($status_file))
  {
    $raw_status = @file_get_contents($status_file);
    $decoded = is_string($raw_status) ? json_decode($raw_status, true) : null;
    if (is_array($decoded))
    {
      $timestamp = isset($decoded['timestamp']) ? (int)$decoded['timestamp'] : 0;
      $sync_status = array(
        'available' => true,
        'state' => isset($decoded['state']) ? (string)$decoded['state'] : '',
        'message' => isset($decoded['message']) ? (string)$decoded['message'] : '',
        'timestamp' => $timestamp,
        'time_label' => $timestamp > 0 ? date('d.m.Y H:i:s', $timestamp) : '',
      );
    }
  }

  $detected = $config_readable && empty($missing);

  return array(
    'phase' => 'Beobachtung',
    'readonly' => true,
    'detected' => $detected,
    'config_path' => $config_path,
    'config_exists' => $config_exists,
    'config_readable' => $config_readable,
    'missing' => $missing,
    'host' => isset($config['NC_DB_HOST']) ? (string)$config['NC_DB_HOST'] : '',
    'port' => isset($config['NC_DB_PORT']) ? (string)$config['NC_DB_PORT'] : '',
    'database' => isset($config['NC_DB_NAME']) ? (string)$config['NC_DB_NAME'] : '',
    'user' => isset($config['NC_DB_USER']) ? (string)$config['NC_DB_USER'] : '',
    'view' => isset($config['NC_DB_VIEW']) ? (string)$config['NC_DB_VIEW'] : '',
    'password_file' => $password_file,
    'password_file_exists' => $password_file !== '' && is_file($password_file),
    'password_file_readable' => $password_file !== '' && is_readable($password_file),
    'gallery_root' => isset($config['GALLERY_ROOT']) ? (string)$config['GALLERY_ROOT'] : '',
    'state_dir' => isset($config['STATE_DIR']) ? (string)$config['STATE_DIR'] : '',
    'status_file' => $status_file,
    'sync_enabled' => isset($config['PIWIGO_SYNC_ENABLED']) && (string)$config['PIWIGO_SYNC_ENABLED'] === '1',
    'quiet_seconds' => isset($config['QUIET_SECONDS']) ? (string)$config['QUIET_SECONDS'] : '',
    'max_wait_seconds' => isset($config['MAX_WAIT_SECONDS']) ? (string)$config['MAX_WAIT_SECONDS'] : '',
    'full_sync_seconds' => isset($config['FULL_SYNC_SECONDS']) ? (string)$config['FULL_SYNC_SECONDS'] : '',
    'sync_status' => $sync_status,
  );
}
