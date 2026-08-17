<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * NC Connector migration phase.
 *
 * The existing /etc/piwigo-sync installation remains the production path.
 * Bratonien Tools imports a copy of its configuration into its own connection
 * store. Importing never changes or stops the legacy sync.
 */

function bratonien_tools_nc_connector_table()
{
  if (function_exists('bratonien_tools_table'))
  {
    return bratonien_tools_table('nc_connections');
  }

  return $GLOBALS['prefixeTable'].'bratonien_tools_nc_connections';
}

function bratonien_tools_nc_connector_ensure_table()
{
  $table = bratonien_tools_nc_connector_table();
  pwg_query("CREATE TABLE IF NOT EXISTS `$table` (
    id int(11) NOT NULL AUTO_INCREMENT,
    connection_key varchar(64) NOT NULL,
    name varchar(255) NOT NULL,
    adapter enum('local','remote') NOT NULL DEFAULT 'local',
    enabled tinyint(1) NOT NULL DEFAULT 0,
    takeover_state enum('imported','verified','active','disabled') NOT NULL DEFAULT 'imported',
    config_json mediumtext NOT NULL,
    secret_blob mediumtext DEFAULT NULL,
    created datetime NOT NULL,
    updated datetime NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY connection_key (connection_key)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function bratonien_tools_nc_connector_secret_key()
{
  global $conf;

  $name = 'bratonien_nc_connector_secret';
  $hex = isset($conf[$name]) ? trim((string)$conf[$name]) : '';
  if (!preg_match('/^[a-f0-9]{64}$/', $hex))
  {
    $hex = bin2hex(random_bytes(32));
    if (function_exists('conf_update_param'))
    {
      conf_update_param($name, $hex);
      $conf[$name] = $hex;
    }
  }

  return hex2bin($hex);
}

function bratonien_tools_nc_connector_encrypt_secret($plain)
{
  $plain = (string)$plain;
  if ($plain === '')
  {
    return '';
  }
  if (!function_exists('openssl_encrypt'))
  {
    throw new RuntimeException('OpenSSL wird zum sicheren Speichern der Connector-Zugangsdaten benoetigt.');
  }

  $iv = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt(
    $plain,
    'aes-256-gcm',
    bratonien_tools_nc_connector_secret_key(),
    OPENSSL_RAW_DATA,
    $iv,
    $tag
  );
  if ($cipher === false)
  {
    throw new RuntimeException('Connector-Zugangsdaten konnten nicht verschluesselt werden.');
  }

  return base64_encode(json_encode(array(
    'v' => 1,
    'iv' => base64_encode($iv),
    'tag' => base64_encode($tag),
    'data' => base64_encode($cipher),
  )));
}

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

function bratonien_tools_nc_connector_connections()
{
  bratonien_tools_nc_connector_ensure_table();
  $table = bratonien_tools_nc_connector_table();
  $result = pwg_query("SELECT id, connection_key, name, adapter, enabled, takeover_state, config_json, created, updated FROM `$table` ORDER BY id");
  $connections = array();

  while ($row = pwg_db_fetch_assoc($result))
  {
    $config = json_decode((string)$row['config_json'], true);
    if (!is_array($config))
    {
      $config = array();
    }
    $row['id'] = (int)$row['id'];
    $row['enabled'] = (bool)$row['enabled'];
    $row['config'] = $config;
    $row['host'] = isset($config['host']) ? (string)$config['host'] : '';
    $row['database'] = isset($config['database']) ? (string)$config['database'] : '';
    $row['user'] = isset($config['user']) ? (string)$config['user'] : '';
    $row['source_view'] = isset($config['source_view']) ? (string)$config['source_view'] : '';
    $row['storage_count'] = isset($config['storages']) && is_array($config['storages']) ? count($config['storages']) : 0;
    $connections[] = $row;
  }

  return $connections;
}

function bratonien_tools_nc_connector_import_bundle_path()
{
  return '/tmp/bratonien-tools-nc-import.json';
}

function bratonien_tools_nc_connector_import_legacy()
{
  $path = bratonien_tools_nc_connector_import_bundle_path();
  if (!is_readable($path))
  {
    throw new RuntimeException('Kein lesbares Migrationspaket gefunden. Fuehre zuerst den angezeigten Connector-Migrationsbefehl im Piwigo-LXC aus.');
  }

  $raw = @file_get_contents($path);
  $bundle = is_string($raw) ? json_decode($raw, true) : null;
  if (!is_array($bundle) || (int)($bundle['format'] ?? 0) !== 1 || !is_array($bundle['config'] ?? null))
  {
    throw new RuntimeException('Das Migrationspaket ist ungueltig oder unvollstaendig.');
  }

  $legacy = $bundle['config'];
  $required = array('NC_DB_HOST', 'NC_DB_PORT', 'NC_DB_NAME', 'NC_DB_USER', 'NC_DB_VIEW');
  foreach ($required as $key)
  {
    if (!isset($legacy[$key]) || trim((string)$legacy[$key]) === '')
    {
      throw new RuntimeException('Im Migrationspaket fehlt '.$key.'.');
    }
  }

  $password = isset($bundle['db_password']) ? (string)$bundle['db_password'] : '';
  if ($password === '')
  {
    throw new RuntimeException('Das Migrationspaket enthaelt kein Datenbankpasswort.');
  }

  $config = array(
    'host' => (string)$legacy['NC_DB_HOST'],
    'port' => (string)$legacy['NC_DB_PORT'],
    'database' => (string)$legacy['NC_DB_NAME'],
    'user' => (string)$legacy['NC_DB_USER'],
    'source_view' => (string)$legacy['NC_DB_VIEW'],
    'activity_view' => isset($legacy['NC_ACTIVITY_VIEW']) ? (string)$legacy['NC_ACTIVITY_VIEW'] : 'piwigo_showcase_activity',
    'gallery_root' => isset($legacy['GALLERY_ROOT']) ? (string)$legacy['GALLERY_ROOT'] : '',
    'state_dir' => isset($legacy['STATE_DIR']) ? (string)$legacy['STATE_DIR'] : '',
    'status_file' => isset($legacy['STATUS_FILE']) ? (string)$legacy['STATUS_FILE'] : '',
    'quiet_seconds' => isset($legacy['QUIET_SECONDS']) ? (int)$legacy['QUIET_SECONDS'] : 120,
    'max_wait_seconds' => isset($legacy['MAX_WAIT_SECONDS']) ? (int)$legacy['MAX_WAIT_SECONDS'] : 900,
    'full_sync_seconds' => isset($legacy['FULL_SYNC_SECONDS']) ? (int)$legacy['FULL_SYNC_SECONDS'] : 86400,
    'storages' => isset($bundle['storages']) && is_array($bundle['storages']) ? $bundle['storages'] : array(),
    'imported_from' => '/etc/piwigo-sync/piwigo.conf',
    'legacy_sync_enabled' => isset($legacy['PIWIGO_SYNC_ENABLED']) && (string)$legacy['PIWIGO_SYNC_ENABLED'] === '1',
  );

  $key_material = $config['host'].'|'.$config['database'].'|'.$config['user'].'|'.$config['source_view'];
  $connection_key = 'legacy-'.substr(hash('sha256', $key_material), 0, 24);
  $table = bratonien_tools_nc_connector_table();
  bratonien_tools_nc_connector_ensure_table();
  $now = date('Y-m-d H:i:s');
  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $secret_blob = bratonien_tools_nc_connector_encrypt_secret($password);

  $escaped_key = pwg_db_real_escape_string($connection_key);
  $existing = pwg_query("SELECT id FROM `$table` WHERE connection_key = '$escaped_key' LIMIT 1");
  if (pwg_db_num_rows($existing))
  {
    $row = pwg_db_fetch_assoc($existing);
    $id = (int)$row['id'];
    pwg_query("UPDATE `$table` SET
      name = 'Bestehende Nextcloud',
      adapter = 'local',
      enabled = 0,
      takeover_state = 'imported',
      config_json = '".pwg_db_real_escape_string($config_json)."',
      secret_blob = '".pwg_db_real_escape_string($secret_blob)."',
      updated = '".pwg_db_real_escape_string($now)."'
      WHERE id = $id");
  }
  else
  {
    pwg_query("INSERT INTO `$table`
      (connection_key, name, adapter, enabled, takeover_state, config_json, secret_blob, created, updated)
      VALUES (
        '$escaped_key',
        'Bestehende Nextcloud',
        'local',
        0,
        'imported',
        '".pwg_db_real_escape_string($config_json)."',
        '".pwg_db_real_escape_string($secret_blob)."',
        '".pwg_db_real_escape_string($now)."',
        '".pwg_db_real_escape_string($now)."'
      )");
  }

  @unlink($path);

  return array(
    'message' => 'Bestehende Nextcloud-Verbindung wurde in den NC Connector importiert. Der produktive Legacy-Sync bleibt unveraendert aktiv; die importierte Verbindung ist noch nicht aktiviert.',
  );
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

  $sync_status = array('available'=>false, 'state'=>'', 'message'=>'', 'timestamp'=>0, 'time_label'=>'');
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

  $connections = bratonien_tools_nc_connector_connections();
  $bundle_path = bratonien_tools_nc_connector_import_bundle_path();

  return array(
    'phase' => 'Migration',
    'readonly' => true,
    'legacy_present' => $config_exists,
    'detected' => $config_exists,
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
    'connections' => $connections,
    'connection_count' => count($connections),
    'migration_bundle_available' => is_readable($bundle_path),
    'migration_bundle_path' => $bundle_path,
    'migration_command' => 'sudo php '.PHPWG_ROOT_PATH.'plugins/'.BRATONIEN_TOOLS_ID.'/nc-connector-migrate.php',
  );
}
