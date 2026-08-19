<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Start the normal WebDAV wizard with the values of an existing connection.
 *
 * Remote/WebDAV connections are edited in place. A legacy/local connection is
 * intentionally not mutated: the wizard prepares its WebDAV successor while
 * the existing connection remains available as fallback.
 */
function bratonien_tools_nc_connector_edit_start()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }

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

  $is_remote = (string)$connection['adapter'] === 'remote'
    && (string)($config['source_mode'] ?? '') === 'webdav-placeholder';

  $state = bratonien_tools_nc_wizard_state();
  $state = array_merge($state, array(
    'editing_connection_id'=>$id,
    'editing_adapter'=>(string)$connection['adapter'],
    'editing_mode'=>$is_remote ? 'update' : 'migrate',
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

  // If WebDAV credentials are already present, open directly in the friendly
  // folder browser. Otherwise start at the login step so the user can add them.
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
      // Do not make the editor unusable because an old credential no longer
      // works. Return to the login page and let the user replace it.
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

  return array(
    'message'=>$is_remote
      ? 'Verbindung #'.$id.' wurde zum Bearbeiten geöffnet.'
      : 'Verbindung #'.$id.' wird im Assistenten auf WebDAV vorbereitet. Die bestehende Legacy-Verbindung bleibt dabei als Fallback erhalten.',
  );
}

function bratonien_tools_nc_connector_update_name()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $name = trim((string)($_POST['connection_name'] ?? ''));
  if ($name === '') throw new RuntimeException('Der Verbindungsname darf nicht leer sein.');

  $connection = bratonien_tools_nc_connector_connection($id, false);
  if (!$connection) throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');

  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET name='".pwg_db_real_escape_string($name)."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".$id." LIMIT 1");

  return array('message'=>'Verbindungsname wurde gespeichert.');
}

/**
 * Legacy-only advanced editor kept for installations that still need the old
 * path during migration. It is deliberately separate from the normal editor.
 */
function bratonien_tools_nc_connector_update_technical()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection) throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  if ((string)$connection['adapter'] !== 'local') throw new RuntimeException('Technische Legacy-Einstellungen sind nur für lokale Altverbindungen verfügbar.');

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $port = (int)($_POST['nc_port'] ?? 5432);
  if ($port < 1 || $port > 65535) throw new RuntimeException('Ungültiger PostgreSQL-Port.');

  $gallery_root = rtrim(trim((string)($_POST['nc_gallery_root'] ?? '')), '/');
  if ($gallery_root === '' || $gallery_root[0] !== '/') throw new RuntimeException('Der Galerie-Pfad muss ein absoluter Pfad sein.');

  $config['host'] = trim((string)($_POST['nc_host'] ?? ''));
  $config['port'] = (string)$port;
  $config['database'] = trim((string)($_POST['nc_database'] ?? ''));
  $config['user'] = trim((string)($_POST['nc_user'] ?? ''));
  $config['source_view'] = trim((string)($_POST['nc_source_view'] ?? ''));
  $config['activity_view'] = trim((string)($_POST['nc_activity_view'] ?? ''));
  $config['gallery_root'] = $gallery_root;
  $config['quiet_seconds'] = max(0, (int)($_POST['nc_quiet_seconds'] ?? 120));
  $config['max_wait_seconds'] = max(60, (int)($_POST['nc_max_wait_seconds'] ?? 900));
  $config['full_sync_seconds'] = max(300, (int)($_POST['nc_full_sync_seconds'] ?? 86400));
  $config['storages'] = bratonien_tools_nc_connector_parse_storages($_POST['nc_storages'] ?? '');

  foreach (array('host','database','user','source_view','activity_view') as $key)
  {
    if (trim((string)$config[$key]) === '') throw new RuntimeException('Die technische Einstellung '.$key.' darf nicht leer sein.');
  }
  bratonien_tools_nc_connector_view_name($config['source_view']);
  bratonien_tools_nc_connector_view_name($config['activity_view']);

  $credentials = bratonien_tools_nc_connector_scoped_secret($connection);
  $new_db_password = (string)($_POST['nc_db_password'] ?? '');
  if ($new_db_password !== '') $credentials['db_password'] = $new_db_password;
  if (trim((string)($credentials['db_password'] ?? '')) === '') throw new RuntimeException('Für die Legacy-Verbindung ist kein Datenbankpasswort gespeichert.');

  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json)) throw new RuntimeException('Connector-Konfiguration konnte nicht serialisiert werden.');
  $payload = json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($payload)) throw new RuntimeException('Connector-Zugangsdaten konnten nicht serialisiert werden.');

  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($config_json)."', secret_blob='".pwg_db_real_escape_string(bratonien_tools_nc_connector_encrypt_secret($payload))."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".$id." LIMIT 1");

  return array('message'=>'Technische Legacy-Einstellungen wurden gespeichert. Der nächste Lauf verwendet die neuen Werte.');
}
