<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_credentials_from_blob($blob)
{
  $plain = bratonien_tools_nc_connector_decrypt_secret($blob);
  if ($plain === '')
  {
    return array('db_password'=>'', 'piwigo_user'=>'', 'piwigo_password'=>'');
  }

  $decoded = json_decode($plain, true);
  if (is_array($decoded) && isset($decoded['db_password']))
  {
    return array(
      'db_password' => (string)($decoded['db_password'] ?? ''),
      'piwigo_user' => (string)($decoded['piwigo_user'] ?? ''),
      'piwigo_password' => (string)($decoded['piwigo_password'] ?? ''),
    );
  }

  // Backwards compatibility for migrated 0.14.x connections.
  return array('db_password'=>$plain, 'piwigo_user'=>'', 'piwigo_password'=>'');
}

function bratonien_tools_nc_connector_encrypt_credentials($db_password, $piwigo_user, $piwigo_password)
{
  $payload = json_encode(array(
    'v' => 1,
    'db_password' => (string)$db_password,
    'piwigo_user' => (string)$piwigo_user,
    'piwigo_password' => (string)$piwigo_password,
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  if (!is_string($payload))
  {
    throw new RuntimeException('Connector-Zugangsdaten konnten nicht serialisiert werden.');
  }

  return bratonien_tools_nc_connector_encrypt_secret($payload);
}

function bratonien_tools_nc_connector_parse_storages($raw)
{
  $storages = array();
  $lines = preg_split('/\r\n|\r|\n/', trim((string)$raw));
  foreach ($lines as $line)
  {
    $line = trim($line);
    if ($line === '')
    {
      continue;
    }
    $parts = array_map('trim', explode('|', $line));
    if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '')
    {
      throw new RuntimeException('Storage-Zeilen muessen das Format storage_id | source_prefix | local_mount verwenden.');
    }
    if ($parts[2][0] !== '/')
    {
      throw new RuntimeException('Der lokale Storage-Mount muss ein absoluter Pfad sein.');
    }
    $storages[] = array(
      'storage_id' => $parts[0],
      'source_prefix' => $parts[1],
      'local_mount' => rtrim($parts[2], '/'),
    );
  }
  if (!$storages)
  {
    throw new RuntimeException('Mindestens ein Storage muss konfiguriert werden.');
  }
  return $storages;
}

function bratonien_tools_nc_connector_create_local()
{
  $required = array(
    'nc_name' => 'Name',
    'nc_host' => 'PostgreSQL-Host',
    'nc_database' => 'Datenbank',
    'nc_user' => 'Reader-Benutzer',
    'nc_db_password' => 'Reader-Passwort',
    'nc_source_view' => 'Source-View',
    'nc_activity_view' => 'Activity-View',
    'nc_gallery_root' => 'Galerie-Pfad',
    'nc_piwigo_user' => 'Piwigo-Benutzer',
    'nc_piwigo_password' => 'Piwigo-Passwort',
  );

  foreach ($required as $field => $label)
  {
    if (trim((string)($_POST[$field] ?? '')) === '')
    {
      throw new RuntimeException($label.' fehlt.');
    }
  }

  $port = (int)($_POST['nc_port'] ?? 5432);
  if ($port < 1 || $port > 65535)
  {
    throw new RuntimeException('Ungueltiger PostgreSQL-Port.');
  }

  $gallery_root = rtrim(trim((string)$_POST['nc_gallery_root']), '/');
  if ($gallery_root === '' || $gallery_root[0] !== '/')
  {
    throw new RuntimeException('Der Galerie-Pfad muss ein absoluter Pfad sein.');
  }

  $config = array(
    'host' => trim((string)$_POST['nc_host']),
    'port' => (string)$port,
    'database' => trim((string)$_POST['nc_database']),
    'user' => trim((string)$_POST['nc_user']),
    'source_view' => trim((string)$_POST['nc_source_view']),
    'activity_view' => trim((string)$_POST['nc_activity_view']),
    'gallery_root' => $gallery_root,
    'state_dir' => '',
    'status_file' => '',
    'quiet_seconds' => max(0, (int)($_POST['nc_quiet_seconds'] ?? 120)),
    'max_wait_seconds' => max(60, (int)($_POST['nc_max_wait_seconds'] ?? 900)),
    'full_sync_seconds' => max(300, (int)($_POST['nc_full_sync_seconds'] ?? 86400)),
    'storages' => bratonien_tools_nc_connector_parse_storages($_POST['nc_storages'] ?? ''),
    'origin' => 'native',
  );

  // Validate view names before persisting.
  bratonien_tools_nc_connector_view_name($config['source_view']);
  bratonien_tools_nc_connector_view_name($config['activity_view']);

  $table = bratonien_tools_nc_connector_table();
  bratonien_tools_nc_connector_ensure_table();
  $now = date('Y-m-d H:i:s');
  $connection_key = 'local-'.bin2hex(random_bytes(12));
  $secret_blob = bratonien_tools_nc_connector_encrypt_credentials(
    (string)$_POST['nc_db_password'],
    trim((string)$_POST['nc_piwigo_user']),
    (string)$_POST['nc_piwigo_password']
  );
  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  pwg_query("INSERT INTO `$table`
    (connection_key, name, adapter, enabled, takeover_state, config_json, secret_blob, created, updated)
    VALUES ('".pwg_db_real_escape_string($connection_key)."',
      '".pwg_db_real_escape_string(trim((string)$_POST['nc_name']))."',
      'local', 0, 'disabled',
      '".pwg_db_real_escape_string($config_json)."',
      '".pwg_db_real_escape_string($secret_blob)."',
      '".pwg_db_real_escape_string($now)."',
      '".pwg_db_real_escape_string($now)."')");

  $id = (int)pwg_db_insert_id();
  $config['state_dir'] = '/var/lib/bratonien-tools/nc-connector/connection-'.$id;
  $config['status_file'] = $config['state_dir'].'/connector-status.json';
  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($config_json)."' WHERE id=".$id);

  return array('message'=>'Nextcloud-Verbindung wurde angelegt. Bitte zuerst technisch pruefen und danach im LXC aktivieren.');
}

function bratonien_tools_nc_connector_delete()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, false);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }
  if (!empty($connection['enabled']) || (string)$connection['takeover_state'] === 'active')
  {
    throw new RuntimeException('Eine aktive Verbindung kann nicht geloescht werden. Sie muss zuerst deaktiviert werden.');
  }

  $table = bratonien_tools_nc_connector_table();
  pwg_query("DELETE FROM `$table` WHERE id=".$id." LIMIT 1");
  return array('message'=>'Connector-Verbindung wurde geloescht.');
}
