<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_migration_state(array $connection)
{
  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $credentials = bratonien_tools_nc_connector_scoped_secret($connection);
  $missing = array();

  if (trim((string)($config['nextcloud_url'] ?? '')) === '') $missing[] = 'Nextcloud-Adresse';
  if (trim((string)($credentials['nextcloud_user'] ?? '')) === '') $missing[] = 'Nextcloud-Benutzer';
  if ((string)($credentials['nextcloud_password'] ?? '') === '') $missing[] = 'Nextcloud-Passwort';
  if (trim((string)($credentials['api_key_id'] ?? '')) === '') $missing[] = 'Piwigo-API-Schluessel-ID';
  if (trim((string)($credentials['api_key_secret'] ?? '')) === '') $missing[] = 'Piwigo-API-Geheimnis';

  return array(
    'ready'=>!$missing,
    'missing'=>$missing,
  );
}

function bratonien_tools_nc_connector_validate_nextcloud_access($base_url, $username, $password)
{
  $base_url = bratonien_tools_nc_wizard_normalize_url($base_url);
  $username = trim((string)$username);
  $password = (string)$password;
  if ($username === '' || $password === '') throw new RuntimeException('Nextcloud-Benutzer und Passwort werden benoetigt.');

  $status_response = bratonien_tools_nc_wizard_http($base_url.'/status.php');
  if ($status_response['status'] < 200 || $status_response['status'] >= 300)
  {
    throw new RuntimeException('Die Nextcloud-Adresse konnte nicht bestaetigt werden.');
  }
  $status = json_decode((string)$status_response['body'], true);
  if (!is_array($status) || empty($status['installed']))
  {
    throw new RuntimeException('Unter dieser Adresse wurde keine installierte Nextcloud erkannt.');
  }

  $user_response = bratonien_tools_nc_wizard_http(
    $base_url.'/ocs/v2.php/cloud/user?format=json',
    $username,
    $password,
    array('OCS-APIRequest: true')
  );
  if ($user_response['status'] === 401 || $user_response['status'] === 403)
  {
    throw new RuntimeException('Nextcloud hat Benutzername oder Passwort abgelehnt.');
  }
  if ($user_response['status'] < 200 || $user_response['status'] >= 300)
  {
    throw new RuntimeException('Nextcloud ist erreichbar, aber der Benutzerzugang konnte nicht geprueft werden.');
  }
  $user_data = bratonien_tools_nc_wizard_ocs_data($user_response['body']);
  $resolved = trim((string)($user_data['id'] ?? $username));

  return array(
    'base_url'=>$base_url,
    'username'=>$resolved !== '' ? $resolved : $username,
  );
}

function bratonien_tools_nc_connector_validate_scoped_api($api_key_id, $api_key_secret)
{
  $api_key_id = trim((string)$api_key_id);
  $api_key_secret = trim((string)$api_key_secret);
  if ($api_key_id === '' || $api_key_secret === '')
  {
    throw new RuntimeException('Piwigo-API-Schluessel-ID und API-Geheimnis werden benoetigt.');
  }

  $status = bratonien_tools_nc_connector_piwigo_api_request($api_key_id, $api_key_secret, 'pwg.session.getStatus');
  if (!is_array($status)) throw new RuntimeException('Piwigo hat keinen auswertbaren API-Benutzerstatus geliefert.');
  $role = strtolower(trim((string)($status['status'] ?? '')));
  if (!in_array($role, array('admin','webmaster'), true))
  {
    throw new RuntimeException('Der API-Key funktioniert, gehoert aber keinem Piwigo-Administrator/Webmaster.');
  }

  $method_result = bratonien_tools_nc_connector_piwigo_api_request($api_key_id, $api_key_secret, 'reflection.getMethodList');
  $method_map = array();
  bratonien_tools_nc_connector_collect_method_names($method_result, $method_map);
  $required = array('bratonien.nc.syncProductive', 'bratonien.nc.syncOrphans');
  $missing = array_values(array_diff($required, array_keys($method_map)));
  if ($missing)
  {
    throw new RuntimeException('Der API-Key ist gueltig, aber benoetigte Bratonien-Sync-Methoden fehlen: '.implode(', ', $missing).'.');
  }
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
    if ($fileid < 1) continue;
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
  return array('message'=>'Verbindung #'.$id.' wurde zum Bearbeiten geoeffnet.');
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

  $migration = bratonien_tools_nc_connector_migration_state($connection);
  if (empty($migration['ready']))
  {
    throw new RuntimeException('Die WebDAV-Migration ist noch nicht bereit. Unter Bearbeiten fehlen: '.implode(', ', $migration['missing']).'.');
  }

  bratonien_tools_nc_connector_prepare_webdav_wizard_from_connection($connection, 'migrate');
  return array('message'=>'Die WebDAV-Migration fuer Verbindung #'.$id.' wurde geoeffnet. Die bestehende Legacy-Verbindung bleibt bis zum erfolgreichen Umstieg unveraendert.');
}

function bratonien_tools_nc_connector_update_local_friendly()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection) throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  if ((string)$connection['adapter'] !== 'local') throw new RuntimeException('Diese Bearbeitung ist nur fuer Legacy-Verbindungen vorgesehen.');

  $name = trim((string)($_POST['connection_name'] ?? ''));
  if ($name === '') throw new RuntimeException('Bitte einen Namen fuer die Verbindung angeben.');

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $host = trim((string)($_POST['nc_host'] ?? ''));
  $port = (int)($_POST['nc_port'] ?? 5432);
  $database = trim((string)($_POST['nc_database'] ?? ''));
  $user = trim((string)($_POST['nc_user'] ?? ''));
  $gallery_root = rtrim(trim((string)($_POST['nc_gallery_root'] ?? '')), '/');
  $source_view = trim((string)($_POST['nc_source_view'] ?? ''));
  $activity_view = trim((string)($_POST['nc_activity_view'] ?? ''));

  if ($host === '') throw new RuntimeException('Datenbank-Server fehlt.');
  if ($port < 1 || $port > 65535) throw new RuntimeException('Der Datenbank-Port ist ungueltig.');
  if ($database === '') throw new RuntimeException('Datenbankname fehlt.');
  if ($user === '') throw new RuntimeException('Reader-Benutzer fehlt.');
  if ($gallery_root === '' || $gallery_root[0] !== '/') throw new RuntimeException('Der Piwigo-Galerieordner muss ein absoluter Pfad sein.');
  if ($source_view === '' || $activity_view === '') throw new RuntimeException('Die gespeicherten Datenbankansichten duerfen nicht leer sein.');
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
    if ($mount === '' || $mount[0] !== '/') throw new RuntimeException('Bei einem Speicherort fehlt ein gueltiger lokaler Pfad.');
    $storages[] = array('storage_id'=>$storage_id, 'source_prefix'=>$prefix, 'local_mount'=>$mount);
  }
  if (!$storages) throw new RuntimeException('Mindestens ein Speicherort muss vorhanden sein.');

  $credentials = bratonien_tools_nc_connector_scoped_secret($connection);
  $new_db_password = (string)($_POST['nc_db_password'] ?? '');
  if ($new_db_password !== '') $credentials['db_password'] = $new_db_password;
  if (trim((string)($credentials['db_password'] ?? '')) === '') throw new RuntimeException('Fuer die Legacy-Verbindung ist kein Datenbankpasswort gespeichert.');

  $nextcloud_url = trim((string)($_POST['nc_nextcloud_url'] ?? ($config['nextcloud_url'] ?? '')));
  $nextcloud_user = trim((string)($_POST['nc_nextcloud_user'] ?? ($credentials['nextcloud_user'] ?? '')));
  $new_nextcloud_password = (string)($_POST['nc_nextcloud_password'] ?? '');
  if ($new_nextcloud_password !== '') $credentials['nextcloud_password'] = $new_nextcloud_password;
  $nextcloud_password = (string)($credentials['nextcloud_password'] ?? '');

  $api_key_id = trim((string)($_POST['nc_connection_api_key_id'] ?? ($credentials['api_key_id'] ?? '')));
  $new_api_secret = trim((string)($_POST['nc_connection_api_key_secret'] ?? ''));
  $old_api_id = trim((string)($credentials['api_key_id'] ?? ''));
  if ($api_key_id !== $old_api_id && $new_api_secret === '')
  {
    throw new RuntimeException('Wenn die API-Schluessel-ID geaendert wird, muss auch das API-Geheimnis neu eingegeben werden.');
  }
  if ($new_api_secret !== '') $credentials['api_key_secret'] = $new_api_secret;
  $api_key_secret = trim((string)($credentials['api_key_secret'] ?? ''));

  if (($nextcloud_url === '' || $nextcloud_user === '' || $nextcloud_password === '') && ($nextcloud_url !== '' || $nextcloud_user !== '' || $nextcloud_password !== ''))
  {
    throw new RuntimeException('Nextcloud-Adresse, Nextcloud-Benutzer und Nextcloud-Passwort muessen fuer WebDAV gemeinsam vollstaendig sein.');
  }
  if (($api_key_id === '') !== ($api_key_secret === ''))
  {
    throw new RuntimeException('Piwigo-API-Schluessel-ID und API-Geheimnis muessen gemeinsam vollstaendig sein.');
  }

  if ($nextcloud_url !== '')
  {
    $validated_nc = bratonien_tools_nc_connector_validate_nextcloud_access($nextcloud_url, $nextcloud_user, $nextcloud_password);
    $nextcloud_url = $validated_nc['base_url'];
    $nextcloud_user = $validated_nc['username'];
    $credentials['nextcloud_user'] = $nextcloud_user;
  }
  if ($api_key_id !== '')
  {
    bratonien_tools_nc_connector_validate_scoped_api($api_key_id, $api_key_secret);
    $credentials['api_key_id'] = $api_key_id;
  }

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
  if ($nextcloud_url !== '')
  {
    $config['nextcloud_url'] = $nextcloud_url;
    $config['access_user'] = $nextcloud_user;
    $config['nextcloud_access_user'] = $nextcloud_user;
  }
  $config['piwigo_auth'] = 'connection-scoped';
  $config['api_enabled'] = $api_key_id !== '' && $api_key_secret !== '';
  unset($config['verification']);

  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json)) throw new RuntimeException('Connector-Konfiguration konnte nicht serialisiert werden.');
  $secret_payload = json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($secret_payload)) throw new RuntimeException('Connector-Zugangsdaten konnten nicht serialisiert werden.');
  $secret_blob = bratonien_tools_nc_connector_encrypt_secret($secret_payload);

  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET name='".pwg_db_real_escape_string($name)."', config_json='".pwg_db_real_escape_string($config_json)."', secret_blob='".pwg_db_real_escape_string($secret_blob)."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".$id." LIMIT 1");

  $updated = bratonien_tools_nc_connector_connection($id, true);
  $migration = $updated ? bratonien_tools_nc_connector_migration_state($updated) : array('ready'=>false);
  return array(
    'message'=>'Verbindung #'.$id.' wurde gespeichert. '.(!empty($migration['ready']) ? 'Die WebDAV-Migration ist jetzt bereit.' : 'Die WebDAV-Migration ist noch nicht vollstaendig vorbereitet.'),
  );
}
