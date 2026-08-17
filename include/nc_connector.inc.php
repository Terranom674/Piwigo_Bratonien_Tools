<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * NC Connector migration and verification phase.
 *
 * The existing /etc/piwigo-sync installation remains the production path.
 * Bratonien Tools imports a copy of its configuration into its own connection
 * store and verifies that copy independently. Importing and verification never
 * change or stop the legacy sync.
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

function bratonien_tools_nc_connector_decrypt_secret($blob)
{
  $blob = trim((string)$blob);
  if ($blob === '')
  {
    return '';
  }
  if (!function_exists('openssl_decrypt'))
  {
    throw new RuntimeException('OpenSSL wird zum Lesen der Connector-Zugangsdaten benoetigt.');
  }

  $outer = base64_decode($blob, true);
  $payload = is_string($outer) ? json_decode($outer, true) : null;
  if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1)
  {
    throw new RuntimeException('Gespeicherte Connector-Zugangsdaten haben ein unbekanntes Format.');
  }

  $iv = base64_decode((string)($payload['iv'] ?? ''), true);
  $tag = base64_decode((string)($payload['tag'] ?? ''), true);
  $cipher = base64_decode((string)($payload['data'] ?? ''), true);
  if (!is_string($iv) || !is_string($tag) || !is_string($cipher))
  {
    throw new RuntimeException('Gespeicherte Connector-Zugangsdaten sind beschaedigt.');
  }

  $plain = openssl_decrypt(
    $cipher,
    'aes-256-gcm',
    bratonien_tools_nc_connector_secret_key(),
    OPENSSL_RAW_DATA,
    $iv,
    $tag
  );
  if ($plain === false)
  {
    throw new RuntimeException('Connector-Zugangsdaten konnten nicht entschluesselt werden.');
  }

  return (string)$plain;
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
  $result = pwg_query("SELECT id, connection_key, name, adapter, enabled, takeover_state, config_json, secret_blob, created, updated FROM `$table` ORDER BY id");
  $connections = array();

  while ($row = pwg_db_fetch_assoc($result))
  {
    $config = json_decode((string)$row['config_json'], true);
    if (!is_array($config))
    {
      $config = array();
    }
    $verification = isset($config['verification']) && is_array($config['verification']) ? $config['verification'] : array();

    $has_fallback = false;
    try
    {
      $plain = bratonien_tools_nc_connector_decrypt_secret($row['secret_blob'] ?? '');
      $credentials = json_decode($plain, true);
      if (is_array($credentials))
      {
        $has_fallback = trim((string)($credentials['piwigo_user'] ?? '')) !== ''
          && (string)($credentials['piwigo_password'] ?? '') !== '';
      }
    }
    catch (Throwable $e)
    {
      $has_fallback = false;
    }

    unset($row['secret_blob']);
    $row['id'] = (int)$row['id'];
    $row['enabled'] = (bool)$row['enabled'];
    $row['config'] = $config;
    $row['host'] = isset($config['host']) ? (string)$config['host'] : '';
    $row['database'] = isset($config['database']) ? (string)$config['database'] : '';
    $row['user'] = isset($config['user']) ? (string)$config['user'] : '';
    $row['source_view'] = isset($config['source_view']) ? (string)$config['source_view'] : '';
    $row['storage_count'] = isset($config['storages']) && is_array($config['storages']) ? count($config['storages']) : 0;
    $row['fallback_stored'] = $has_fallback;
    $row['verification'] = $verification;
    $row['verified_ok'] = !empty($verification['ok']);
    $row['verified_at'] = isset($verification['checked_at']) ? (string)$verification['checked_at'] : '';
    $row['source_count'] = isset($verification['source_count']) ? (int)$verification['source_count'] : null;
    $row['verification_checks'] = isset($verification['checks']) && is_array($verification['checks']) ? $verification['checks'] : array();
    $connections[] = $row;
  }

  return $connections;
}

function bratonien_tools_nc_connector_connection($id, $with_secret = false)
{
  bratonien_tools_nc_connector_ensure_table();
  $table = bratonien_tools_nc_connector_table();
  $id = (int)$id;
  if ($id <= 0)
  {
    return null;
  }

  $columns = 'id, connection_key, name, adapter, enabled, takeover_state, config_json, created, updated';
  if ($with_secret)
  {
    $columns .= ', secret_blob';
  }
  $result = pwg_query("SELECT $columns FROM `$table` WHERE id = $id LIMIT 1");
  if (!pwg_db_num_rows($result))
  {
    return null;
  }

  $row = pwg_db_fetch_assoc($result);
  $row['id'] = (int)$row['id'];
  $row['enabled'] = (bool)$row['enabled'];
  $row['config'] = json_decode((string)$row['config_json'], true);
  if (!is_array($row['config']))
  {
    $row['config'] = array();
  }
  return $row;
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

function bratonien_tools_nc_connector_view_name($name)
{
  $name = trim((string)$name);
  if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $name))
  {
    throw new RuntimeException('Ungueltiger PostgreSQL-View-Name in der Connector-Konfiguration.');
  }

  $parts = explode('.', $name);
  $quoted = array();
  foreach ($parts as $part)
  {
    $quoted[] = '"'.str_replace('"', '""', $part).'"';
  }
  return implode('.', $quoted);
}

function bratonien_tools_nc_connector_psql($config, $password, $query)
{
  $psql = '/usr/bin/psql';
  if (!is_executable($psql))
  {
    throw new RuntimeException('PostgreSQL-Client /usr/bin/psql ist nicht installiert oder nicht ausfuehrbar.');
  }
  if (!function_exists('proc_open'))
  {
    throw new RuntimeException('PHP-Funktion proc_open ist deaktiviert; Connector-Verifikation kann nicht ausgefuehrt werden.');
  }

  $command = array(
    $psql,
    '-X', '-A', '-t',
    '-v', 'ON_ERROR_STOP=1',
    '-h', (string)$config['host'],
    '-p', (string)$config['port'],
    '-U', (string)$config['user'],
    '-d', (string)$config['database'],
    '-c', (string)$query,
  );
  $descriptors = array(
    0 => array('pipe', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
  );
  $env = array(
    'PGPASSWORD' => (string)$password,
    'PGCONNECT_TIMEOUT' => '5',
    'LC_ALL' => 'C',
  );

  $process = @proc_open($command, $descriptors, $pipes, null, $env);
  if (!is_resource($process))
  {
    throw new RuntimeException('PostgreSQL-Pruefprozess konnte nicht gestartet werden.');
  }

  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit = proc_close($process);

  $stdout = trim((string)$stdout);
  $stderr = trim((string)$stderr);
  if ($exit !== 0)
  {
    $detail = $stderr !== '' ? $stderr : 'psql Exit-Code '.$exit;
    throw new RuntimeException('PostgreSQL-Abfrage fehlgeschlagen: '.$detail);
  }

  return $stdout;
}

function bratonien_tools_nc_connector_mount_points()
{
  $mounts = array();
  $content = @file_get_contents('/proc/self/mountinfo');
  if (!is_string($content))
  {
    return $mounts;
  }
  foreach (preg_split('/\r\n|\r|\n/', $content) as $line)
  {
    if ($line === '') continue;
    $parts = explode(' ', $line);
    if (count($parts) < 5) continue;
    $mount = str_replace(array('\\040','\\011','\\012','\\134'), array(' ',"\t","\n",'\\'), $parts[4]);
    $mounts[rtrim($mount, '/')] = true;
  }
  return $mounts;
}

function bratonien_tools_nc_connector_status()
{
  $connections = bratonien_tools_nc_connector_connections();
  $legacy_config = '/etc/piwigo-sync/piwigo.conf';
  $legacy_present = is_readable($legacy_config);
  $active_count = 0;
  foreach ($connections as $connection)
  {
    if (!empty($connection['enabled'])) $active_count++;
  }

  return array(
    'phase' => 'native-runtime',
    'legacy_config_path' => $legacy_config,
    'legacy_present' => $legacy_present,
    'connections' => $connections,
    'connection_count' => count($connections),
    'active_count' => $active_count,
  );
}
