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
  if (!is_string($config_json))
  {
    throw new RuntimeException('Connector-Konfiguration konnte nicht serialisiert werden.');
  }

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

function bratonien_tools_nc_connector_verify_managed()
{
  $id = isset($_POST['connection_id']) ? (int)$_POST['connection_id'] : 0;
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }
  if ((string)$connection['adapter'] !== 'local')
  {
    throw new RuntimeException('Diese Verifikation ist derzeit nur fuer lokale Connector-Verbindungen verfuegbar.');
  }

  $config = $connection['config'];
  foreach (array('host','port','database','user','source_view','activity_view') as $key)
  {
    if (trim((string)($config[$key] ?? '')) === '')
    {
      throw new RuntimeException('Connector-Konfiguration ist unvollstaendig: '.$key.' fehlt.');
    }
  }

  $credentials = bratonien_tools_nc_connector_credentials_from_blob($connection['secret_blob'] ?? '');
  if ($credentials['db_password'] === '')
  {
    throw new RuntimeException('Fuer diese Verbindung ist kein Datenbankpasswort gespeichert.');
  }

  $checks = array();
  $ok = true;
  $source_count = null;

  try
  {
    bratonien_tools_nc_connector_psql($config, $credentials['db_password'], 'SELECT 1');
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
      $source_view = bratonien_tools_nc_connector_view_name($config['source_view']);
      $value = bratonien_tools_nc_connector_psql($config, $credentials['db_password'], 'SELECT COUNT(*) FROM '.$source_view);
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
      $activity_view = bratonien_tools_nc_connector_view_name($config['activity_view']);
      bratonien_tools_nc_connector_psql($config, $credentials['db_password'], 'SELECT 1 FROM '.$activity_view.' LIMIT 1');
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
  if (!$storages)
  {
    $checks[] = array('name'=>'Storage-Mounts', 'ok'=>false, 'detail'=>'Keine Storage-Zuordnung gespeichert');
    $ok = false;
  }
  else
  {
    foreach ($storages as $index => $storage)
    {
      $path = rtrim((string)($storage['local_mount'] ?? ''), '/');
      $exists = $path !== '' && is_dir($path);
      $readable = $exists && is_readable($path);
      $mounted = $path !== '' && isset($mount_points[$path]);
      $storage_ok = $exists && $readable && $mounted;
      $storage_id = (string)($storage['storage_id'] ?? ('#'.($index + 1)));
      $detail = $storage_id.' -> '.($path !== '' ? $path : '(kein Pfad)');
      if (!$exists) $detail .= ' (Pfad fehlt)';
      elseif (!$readable) $detail .= ' (nicht lesbar)';
      elseif (!$mounted) $detail .= ' (kein aktiver Mount)';
      else $detail .= ' (bereit)';
      $checks[] = array('name'=>'Storage '.($index + 1), 'ok'=>$storage_ok, 'detail'=>$detail);
      if (!$storage_ok) $ok = false;
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
  $state = $ok ? 'verified' : ((string)$connection['takeover_state'] === 'disabled' ? 'disabled' : 'imported');
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET takeover_state='".pwg_db_real_escape_string($state)."', enabled=0, config_json='".pwg_db_real_escape_string($config_json)."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".$id);

  if (!$ok)
  {
    throw new RuntimeException('Connector-Verifikation ist fehlgeschlagen. Die Verbindung bleibt deaktiviert; Details stehen im NC Connector.');
  }

  return array('message'=>'Connector-Verbindung wurde erfolgreich verifiziert. PostgreSQL, Views und Storage-Mounts sind erreichbar.');
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
