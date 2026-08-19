<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_prepare_webdav_wizard_from_connection(array $connection, $mode)
{
  $id = (int)$connection['id'];
  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $credentials = bratonien_tools_nc_connector_scoped_secret($connection);

  $base_url = trim((string)($config['nextcloud_url'] ?? ''));
  $username = trim((string)($credentials['nextcloud_user'] ?? ''));
  if ($username === '')
  {
    $username = trim((string)($config['nextcloud_access_user'] ?? $config['access_user'] ?? ''));
  }
  $password = (string)($credentials['nextcloud_password'] ?? '');

  $roots = isset($config['roots']) && is_array($config['roots']) ? array_values($config['roots']) : array();
  $selected = array();
  $selected_ids = array();
  foreach ($roots as $root)
  {
    $path = trim((string)($root['webdav_path'] ?? ''), '/');
    $fileid = (int)($root['fileid'] ?? 0);
    if ($path === '' || $fileid < 1) continue;
    $selected[] = $path;
    $selected_ids[$path] = $fileid;
  }

  $state = bratonien_tools_nc_wizard_state();
  $state = array_merge($state, array(
    'editing_connection_id'=>$id,
    'editing_adapter'=>(string)$connection['adapter'],
    'editing_mode'=>(string)$mode,
    'connection_name'=>(string)$connection['name'],
    'host_input'=>$base_url,
    'base_url'=>$base_url,
    'username'=>$username,
    '_password'=>$password,
    '_fallback_user'=>(string)($credentials['piwigo_user'] ?? ''),
    '_fallback_password'=>(string)($credentials['piwigo_password'] ?? ''),
    '_api_key_id'=>(string)($credentials['api_key_id'] ?? ''),
    '_api_key_secret'=>(string)($credentials['api_key_secret'] ?? ''),
    'api_status'=>trim((string)($credentials['api_key_id'] ?? '')) !== '' && trim((string)($credentials['api_key_secret'] ?? '')) !== '' ? 'ok' : 'pending',
    'roots'=>$roots,
    'directory_selected'=>$selected,
    'directory_selected_fileids'=>$selected_ids,
    'source_mode'=>'webdav-placeholder',
    'transport'=>'webdav',
  ));

  if ($base_url !== '' && $username !== '' && $password !== '')
  {
    $state['step'] = 2;
    $state['scan_ok'] = true;
    $state['technical_stage'] = 'mounts';
    $state['technical_source'] = 'WebDAV';
    $state['technical_error'] = '';
    $state['technical_complete'] = false;
    $state['directory_selection_ready'] = true;
    $state['directory_path'] = '';
    $state['directory_parent'] = '';
    $state['directory_children'] = array();
    $state['directory_current_fileid'] = 0;

    try
    {
      bratonien_tools_nc_wizard_refresh_directory_state($state, '');
    }
    catch (Throwable $e)
    {
      $state['step'] = 1;
      $state['scan_ok'] = false;
      $state['technical_error'] = $e->getMessage();
    }
  }
  else
  {
    $state['step'] = 1;
    $state['scan_ok'] = false;
  }

  bratonien_tools_nc_wizard_store($state);
}

function bratonien_tools_nc_connector_edit_start()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection) throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $is_webdav = (string)$connection['adapter'] === 'remote'
    && (string)($config['source_mode'] ?? '') === 'webdav-placeholder';
  if (!$is_webdav)
  {
    throw new RuntimeException('Diese Legacy-Verbindung wird direkt bearbeitet. Eine WebDAV-Migration ist eine separate Aktion.');
  }

  bratonien_tools_nc_connector_prepare_webdav_wizard_from_connection($connection, 'update');
  return array('message'=>'Verbindung #'.$id.' wurde zum Bearbeiten geöffnet.');
}

function bratonien_tools_nc_connector_migrate_start()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection) throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  if ((string)$connection['adapter'] !== 'local')
  {
    throw new RuntimeException('Nur eine Legacy-Verbindung kann auf WebDAV migriert werden.');
  }

  bratonien_tools_nc_connector_prepare_webdav_wizard_from_connection($connection, 'migrate');
  return array('message'=>'Die WebDAV-Migration für Verbindung #'.$id.' wurde geöffnet. Die bestehende Legacy-Verbindung bleibt bis zum erfolgreichen Umstieg unverändert.');
}

function bratonien_tools_nc_connector_update_local_friendly()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection) throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  if ((string)$connection['adapter'] !== 'local') throw new RuntimeException('Diese Bearbeitung ist nur für Legacy-Verbindungen vorgesehen.');

  $name = trim((string)($_POST['connection_name'] ?? ''));
  if ($name === '') throw new RuntimeException('Bitte einen Namen für die Verbindung angeben.');

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $host = trim((string)($_POST['nc_host'] ?? ''));
  $port = (int)($_POST['nc_port'] ?? 5432);
  $database = trim((string)($_POST['nc_database'] ?? ''));
  $user = trim((string)($_POST['nc_user'] ?? ''));
  $gallery_root = rtrim(trim((string)($_POST['nc_gallery_root'] ?? '')), '/');
  $source_view = trim((string)($_POST['nc_source_view'] ?? ''));
  $activity_view = trim((string)($_POST['nc_activity_view'] ?? ''));

  if ($host === '') throw new RuntimeException('Datenbank-Server fehlt.');
  if ($port < 1 || $port > 65535) throw new RuntimeException('Der Datenbank-Port ist ungültig.');
  if ($database === '') throw new RuntimeException('Datenbankname fehlt.');
  if ($user === '') throw new RuntimeException('Reader-Benutzer fehlt.');
  if ($gallery_root === '' || $gallery_root[0] !== '/') throw new RuntimeException('Der Piwigo-Galerieordner muss ein absoluter Pfad sein.');
  if ($source_view === '' || $activity_view === '') throw new RuntimeException('Die gespeicherten Datenbankansichten dürfen nicht leer sein.');
  bratonien_tools_nc_connector_view_name($source_view);
  bratonien_tools_nc_connector_view_name($activity_view);

  $storage_ids = isset($_POST['nc_storage_id']) && is_array($_POST['nc_storage_id']) ? $_POST['nc_storage_id'] : array();
  $storage_prefixes = isset($_POST['nc_source_prefix']) && is_array($_POST['nc_source_prefix']) ? $_POST['nc_source_prefix'] : array();
  $storage_mounts = isset($_POST['nc_local_mount']) && is_array($_POST['nc_local_mount']) ? $_POST['nc_local_mount'] : array();
  $storages = array();
  $count = max(count($storage_ids), count($storage_prefixes), count($storage_mounts));
  for ($index = 0; $index < $count; $index++)
  {
    $storage_id = trim((string)($storage_ids[$index] ?? ''));
    $prefix = trim((string)($storage_prefixes[$index] ?? ''), '/');
    $mount = rtrim(trim((string)($storage_mounts[$index] ?? '')), '/');
    if ($storage_id === '' && $prefix === '' && $mount === '') continue;
    if ($storage_id === '') throw new RuntimeException('Bei einem Speicherort fehlt die Storage-ID.');
    if ($mount === '' || $mount[0] !== '/') throw new RuntimeException('Bei einem Speicherort fehlt ein gültiger lokaler Pfad.');
    $storages[] = array('storage_id'=>$storage_id, 'source_prefix'=>$prefix, 'local_mount'=>$mount);
  }
  if (!$storages) throw new RuntimeException('Mindestens ein Speicherort muss vorhanden sein.');

  $config['host'] = $host;
  $config['port'] = (string)$port;
  $config['database'] = $database;
  $config['user'] = $user;
  $config['source_view'] = $source_view;
  $config['activity_view'] = $activity_view;
  $config['gallery_root'] = $gallery_root;
  $config['quiet_seconds'] = max(0, (int)($_POST['nc_quiet_seconds'] ?? ($config['quiet_seconds'] ?? 120)));
  $config['max_wait_seconds'] = max(60, (int)($_POST['nc_max_wait_seconds'] ?? ($config['max_wait_seconds'] ?? 900)));
  $config['full_sync_seconds'] = max(300, (int)($_POST['nc_full_sync_seconds'] ?? ($config['full_sync_seconds'] ?? 86400)));
  $config['storages'] = $storages;
  unset($config['verification']);

  $credentials = bratonien_tools_nc_connector_scoped_secret($connection);
  $new_db_password = (string)($_POST['nc_db_password'] ?? '');
  if ($new_db_password !== '') $credentials['db_password'] = $new_db_password;
  if (trim((string)($credentials['db_password'] ?? '')) === '') throw new RuntimeException('Für die Legacy-Verbindung ist kein Datenbankpasswort gespeichert.');

  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json)) throw new RuntimeException('Connector-Konfiguration konnte nicht serialisiert werden.');
  $secret_payload = json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($secret_payload)) throw new RuntimeException('Connector-Zugangsdaten konnten nicht serialisiert werden.');
  $secret_blob = bratonien_tools_nc_connector_encrypt_secret($secret_payload);

  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET name='".pwg_db_real_escape_string($name)."', config_json='".pwg_db_real_escape_string($config_json)."', secret_blob='".pwg_db_real_escape_string($secret_blob)."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".$id." LIMIT 1");

  return array('message'=>'Verbindung #'.$id.' wurde gespeichert. Die neuen Werte gelten ab dem nächsten Connector-Lauf.');
}
