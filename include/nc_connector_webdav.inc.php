<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Create or update one independent WebDAV connector connection.
 * No migration pair, successor or implicit legacy fallback is created.
 */
function bratonien_tools_nc_connector_create_webdav_placeholder_from_wizard()
{
  $state = bratonien_tools_nc_wizard_state();

  if (empty($state['scan_ok']) || empty($state['technical_complete']))
  {
    throw new RuntimeException('Die WebDAV-Verbindung wurde im Assistenten noch nicht vollstaendig vorbereitet.');
  }

  $base_url = rtrim(trim((string)($state['base_url'] ?? '')), '/');
  $username = trim((string)($state['username'] ?? ''));
  $password = (string)($state['_password'] ?? '');
  if ($base_url === '' || $username === '' || $password === '')
  {
    throw new RuntimeException('Fuer den WebDAV-Zugang fehlen Nextcloud-Adresse oder Zugangsdaten.');
  }

  $selected = isset($state['directory_selected']) && is_array($state['directory_selected'])
    ? array_values(array_unique(array_map(function($path) { return trim((string)$path, '/'); }, $state['directory_selected'])))
    : array();
  $selected_ids = isset($state['directory_selected_fileids']) && is_array($state['directory_selected_fileids'])
    ? $state['directory_selected_fileids']
    : array();

  if (!$selected)
  {
    $selected = array('');
  }

  $roots = array();
  foreach ($selected as $path)
  {
    $fileid = isset($selected_ids[$path]) ? (int)$selected_ids[$path] : 0;
    if ($fileid < 1)
    {
      throw new RuntimeException('Fuer ein ausgewaehltes Nextcloud-Verzeichnis fehlt die eindeutige Datei-ID.');
    }
    $display_name = $path === ''
      ? (trim((string)($state['display_name'] ?? '')) !== '' ? trim((string)$state['display_name']) : $username)
      : basename($path);
    $roots[] = array(
      'fileid'=>$fileid,
      'display_name'=>$display_name,
      'webdav_path'=>$path,
    );
  }

  $name = trim((string)($state['connection_name'] ?? ''));
  if ($name === '') $name = 'Nextcloud WebDAV';
  $gallery_root = rtrim(trim((string)($state['gallery_root'] ?? '')), '/');
  if ($gallery_root === '') $gallery_root = rtrim(PHPWG_ROOT_PATH, '/').'/galleries';

  $api_key_id = ($state['api_status'] ?? '') === 'ok' ? trim((string)($state['_api_key_id'] ?? '')) : '';
  $api_key_secret = ($state['api_status'] ?? '') === 'ok' ? trim((string)($state['_api_key_secret'] ?? '')) : '';
  $fallback_user = trim((string)($state['_fallback_user'] ?? ''));
  $fallback_password = (string)($state['_fallback_password'] ?? '');
  $api_enabled = $api_key_id !== '' && $api_key_secret !== '';

  $editing_id = (int)($state['editing_connection_id'] ?? 0);
  $editing_mode = (string)($state['editing_mode'] ?? '');
  $editing_connection = $editing_id > 0 ? bratonien_tools_nc_connector_connection($editing_id, false) : null;
  $editing_remote = $editing_connection
    && (string)$editing_connection['adapter'] === 'remote'
    && (string)($editing_connection['config']['source_mode'] ?? '') === 'webdav-placeholder'
    && $editing_mode === 'update';

  if ($editing_mode === 'migrate')
  {
    throw new RuntimeException('Die Migrationsfunktion wurde entfernt. Bitte eine normale WebDAV-Verbindung anlegen.');
  }

  $config = array(
    'origin'=>'native',
    'source_mode'=>'webdav-placeholder',
    'transport'=>'webdav',
    'nextcloud_url'=>$base_url,
    'access_user'=>$username,
    'nextcloud_access_user'=>$username,
    'gallery_root'=>$gallery_root,
    'roots'=>$roots,
    'state_dir'=>'',
    'status_file'=>'',
    'quiet_seconds'=>120,
    'max_wait_seconds'=>900,
    'full_sync_seconds'=>86400,
    'piwigo_auth'=>'connection-scoped',
    'api_enabled'=>$api_enabled,
  );

  if ($editing_remote)
  {
    $old_config = is_array($editing_connection['config'] ?? null) ? $editing_connection['config'] : array();
    foreach (array('state_dir','status_file','parallel_gallery_root','source_fingerprint','runtime') as $preserve)
    {
      if (array_key_exists($preserve, $old_config)) $config[$preserve] = $old_config[$preserve];
    }
    foreach (array('quiet_seconds','max_wait_seconds','full_sync_seconds') as $preserve)
    {
      if (isset($old_config[$preserve])) $config[$preserve] = (int)$old_config[$preserve];
    }
  }

  foreach (array('product'=>'nextcloud_product', 'version'=>'nextcloud_version') as $state_key=>$config_key)
  {
    $value = trim((string)($state[$state_key] ?? ''));
    if ($value !== '') $config[$config_key] = $value;
  }

  $secret_payload = json_encode(array(
    'v'=>3,
    'db_password'=>'',
    'piwigo_user'=>$fallback_user,
    'piwigo_password'=>$fallback_password,
    'api_key_id'=>$api_key_id,
    'api_key_secret'=>$api_key_secret,
    'nextcloud_user'=>$username,
    'nextcloud_password'=>$password,
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($secret_payload)) throw new RuntimeException('WebDAV-Zugangsdaten konnten nicht serialisiert werden.');

  bratonien_tools_nc_connector_ensure_table();
  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  $secret_blob = bratonien_tools_nc_connector_encrypt_secret($secret_payload);

  if ($editing_remote)
  {
    $config['state_dir'] = trim((string)($config['state_dir'] ?? '')) !== '' ? $config['state_dir'] : '/var/lib/bratonien-tools/nc-connector/connection-'.$editing_id;
    $config['status_file'] = trim((string)($config['status_file'] ?? '')) !== '' ? $config['status_file'] : $config['state_dir'].'/connector-status.json';
    $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($config_json)) throw new RuntimeException('WebDAV-Konfiguration konnte nicht serialisiert werden.');

    pwg_query("UPDATE `$table` SET name='".pwg_db_real_escape_string($name)."', config_json='".pwg_db_real_escape_string($config_json)."', secret_blob='".pwg_db_real_escape_string($secret_blob)."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".$editing_id." AND adapter='remote' LIMIT 1");
    unset($_SESSION['bratonien_nc_wizard']);

    return array(
      'connection_id'=>$editing_id,
      'message'=>'WebDAV-Verbindung wurde gespeichert.',
    );
  }

  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json)) throw new RuntimeException('WebDAV-Konfiguration konnte nicht serialisiert werden.');

  $connection_key = 'webdav-'.bin2hex(random_bytes(12));
  pwg_query("INSERT INTO `$table` (connection_key,name,adapter,enabled,takeover_state,config_json,secret_blob,created,updated) VALUES ('"
    .pwg_db_real_escape_string($connection_key)."','"
    .pwg_db_real_escape_string($name)."','remote',0,'disabled','"
    .pwg_db_real_escape_string($config_json)."','"
    .pwg_db_real_escape_string($secret_blob)."','"
    .pwg_db_real_escape_string($now)."','"
    .pwg_db_real_escape_string($now)."')");

  $id = (int)pwg_db_insert_id();
  if ($id < 1) throw new RuntimeException('Die WebDAV-Verbindung konnte nicht eindeutig angelegt werden.');

  $config['state_dir'] = '/var/lib/bratonien-tools/nc-connector/connection-'.$id;
  $config['status_file'] = $config['state_dir'].'/connector-status.json';
  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json)) throw new RuntimeException('WebDAV-Konfiguration konnte nach dem Anlegen nicht serialisiert werden.');
  pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($config_json)."' WHERE id=".$id." AND adapter='remote' LIMIT 1");

  unset($_SESSION['bratonien_nc_wizard']);

  return array(
    'connection_id'=>$id,
    'message'=>'WebDAV-Verbindung wurde angelegt.',
  );
}
