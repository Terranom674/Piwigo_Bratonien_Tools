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
  $result = pwg_query("SELECT id, connection_key, name, adapter, enabled, takeover_state, config_json, created, updated FROM `$table` ORDER BY id");
  $connections = array();

  while ($row = pwg_db_fetch_assoc($result))
  {
    $config = json_decode((string)$row['config_json'], true);
    if (!is_array($config))
    {
      $config = array();
    }
    $verification = isset($config['verification']) && is_array($config['verification']) ? $config['verification'] : array();
    $row['id'] = (int)$row['id'];
    $row['enabled'] = (bool)$row['enabled'];
    $row['config'] = $config;
    $row['host'] = isset($config['host']) ? (string)$config['host'] : '';
    $row['database'] = isset($config['database']) ? (string)$config['database'] : '';
    $row['user'] = isset($config['user']) ? (string)$config['user'] : '';
    $row['source_view'] = isset($config['source_view']) ? (string)$config['source_view'] : '';
    $row['storage_count'] = isset($config['storages']) && is_array($config['storages']) ? count($config['storages']) : 0;
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
    throw new RuntimeException('PostgreSQL-Pruefung fehlgeschlagen: '.substr($detail, 0, 500));
  }

  return $stdout;
}

function bratonien_tools_nc_connector_mount_points()
{
  $mounts = array();
  $lines = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($lines))
  {
    return $mounts;
  }

  foreach ($lines as $line)
  {
    $parts = explode(' ', $line);
    if (count($parts) < 5)
    {
      continue;
    }
    $path = str_replace(array('\\040','\\011','\\012','\\134'), array(' ',"\t","\n",'\\'), $parts[4]);
    $mounts[$path] = true;
  }
  return $mounts;
}

function bratonien_tools_nc_connector_verify()
{
  $id = isset($_POST['connection_id']) ? (int)$_POST['connection_id'] : 0;
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }
  if ($connection['adapter'] !== 'local')
  {
    throw new RuntimeException('Diese Verifikation ist derzeit nur fuer lokale Connector-Verbindungen verfuegbar.');
  }

  $config = $connection['config'];
  $required = array('host','port','database','user','source_view');
  foreach ($required as $key)
  {
    if (!isset($config[$key]) || trim((string)$config[$key]) === '')
    {
      throw new RuntimeException('Connector-Konfiguration ist unvollstaendig: '.$key.' fehlt.');
    }
  }

  $checks = array();
  $ok = true;
  $source_count = null;
  $password = bratonien_tools_nc_connector_decrypt_secret($connection['secret_blob'] ?? '');
  if ($password === '')
  {
    throw new RuntimeException('Fuer diese Verbindung sind keine Datenbank-Zugangsdaten gespeichert.');
  }

  try
  {
    bratonien_tools_nc_connector_psql($config, $password, 'SELECT 1');
    $checks[] = array('name'=>'PostgreSQL-Verbindung', 'ok'=>true, 'detail'=>'Reader-Anmeldung erfolgreich');
  }
  catch (Throwable $e)
  {
    $checks[] = array('name'=>'PostgreSQL-Verbindung', 'ok'=>false, 'detail'=>$e->getMessage());
    $ok = false;
  }

  if ($ok)
  {
    try
    {
      $view = bratonien_tools_nc_connector_view_name($config['source_view']);
      $value = bratonien_tools_nc_connector_psql($config, $password, 'SELECT COUNT(*) FROM '.$view);
      if (!preg_match('/^\d+$/', $value))
      {
        throw new RuntimeException('Source-View lieferte keinen gueltigen Zaehler.');
      }
      $source_count = (int)$value;
      $checks[] = array('name'=>'Source-View', 'ok'=>true, 'detail'=>$source_count.' Quelle(n) lesbar');
    }
    catch (Throwable $e)
    {
      $checks[] = array('name'=>'Source-View', 'ok'=>false, 'detail'=>$e->getMessage());
      $ok = false;
    }

    try
    {
      $activity = isset($config['activity_view']) ? trim((string)$config['activity_view']) : '';
      if ($activity === '')
      {
        throw new RuntimeException('Keine Activity-View konfiguriert.');
      }
      $view = bratonien_tools_nc_connector_view_name($activity);
      bratonien_tools_nc_connector_psql($config, $password, 'SELECT 1 FROM '.$view.' LIMIT 1');
      $checks[] = array('name'=>'Activity-View', 'ok'=>true, 'detail'=>'View lesbar');
    }
    catch (Throwable $e)
    {
      $checks[] = array('name'=>'Activity-View', 'ok'=>false, 'detail'=>$e->getMessage());
      $ok = false;
    }
  }

  $storages = isset($config['storages']) && is_array($config['storages']) ? $config['storages'] : array();
  $mount_points = bratonien_tools_nc_connector_mount_points();
  if (count($storages) === 0)
  {
    $checks[] = array('name'=>'Storage-Mounts', 'ok'=>false, 'detail'=>'Keine Storage-Zuordnung gespeichert');
    $ok = false;
  }
  else
  {
    foreach ($storages as $index => $storage)
    {
      $path = isset($storage['local_mount']) ? rtrim((string)$storage['local_mount'], '/') : '';
      if ($path === '')
      {
        $checks[] = array('name'=>'Storage '.($index + 1), 'ok'=>false, 'detail'=>'Kein lokaler Mountpfad gespeichert');
        $ok = false;
        continue;
      }

      $exists = is_dir($path);
      $readable = $exists && is_readable($path);
      $mounted = isset($mount_points[$path]);
      $storage_ok = $exists && $readable && $mounted;
      $storage_id = isset($storage['storage_id']) ? (string)$storage['storage_id'] : ('#'.($index + 1));
      $detail = $storage_id.' -> '.$path;
      if (!$exists)
      {
        $detail .= ' (Pfad fehlt)';
      }
      elseif (!$readable)
      {
        $detail .= ' (nicht lesbar)';
      }
      elseif (!$mounted)
      {
        $detail .= ' (kein aktiver Mount)';
      }
      else
      {
        $detail .= ' (bereit)';
      }
      $checks[] = array('name'=>'Storage '.($index + 1), 'ok'=>$storage_ok, 'detail'=>$detail);
      if (!$storage_ok)
      {
        $ok = false;
      }
    }
  }

  $config['verification'] = array(
    'checked_at' => date('Y-m-d H:i:s'),
    'ok' => $ok,
    'source_count' => $source_count,
    'checks' => $checks,
  );
  $table = bratonien_tools_nc_connector_table();
  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json))
  {
    throw new RuntimeException('Verifikationsergebnis konnte nicht gespeichert werden.');
  }
  $state = $ok ? 'verified' : 'imported';
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET
    takeover_state = '".pwg_db_real_escape_string($state)."',
    enabled = 0,
    config_json = '".pwg_db_real_escape_string($config_json)."',
    updated = '".pwg_db_real_escape_string($now)."'
    WHERE id = ".(int)$connection['id']);

  if (!$ok)
  {
    throw new RuntimeException('Connector-Verifikation ist fehlgeschlagen. Die Verbindung bleibt importiert und deaktiviert; Details stehen im NC Connector.');
  }

  return array(
    'message' => 'Connector-Verbindung wurde erfolgreich verifiziert. PostgreSQL, Views und Storage-Mounts sind erreichbar. Der Legacy-Sync bleibt weiterhin aktiv; die Connector-Verbindung bleibt deaktiviert.',
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
  $helper_path = realpath(BRATONIEN_TOOLS_PATH.'nc-connector-migrate.php');
  if ($helper_path === false)
  {
    $helper_path = BRATONIEN_TOOLS_PATH.'nc-connector-migrate.php';
  }

  $verified_count = 0;
  foreach ($connections as $connection)
  {
    if ($connection['takeover_state'] === 'verified' || $connection['takeover_state'] === 'active')
    {
      $verified_count++;
    }
  }

  return array(
    'phase' => $verified_count > 0 ? 'Verifikation' : 'Migration',
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
    'verified_count' => $verified_count,
    'migration_bundle_available' => is_readable($bundle_path),
    'migration_bundle_path' => $bundle_path,
    'migration_command' => 'sudo php '.$helper_path,
  );
}
