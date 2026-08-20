<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_create_webdav_placeholder_from_wizard()
{
  $state = bratonien_tools_nc_wizard_state();

  if (empty($state['scan_ok']) || empty($state['technical_complete']))
  {
    throw new RuntimeException('Die WebDAV-Verbindung wurde im Assistenten noch nicht vollständig vorbereitet.');
  }

  $base_url = rtrim(trim((string)($state['base_url'] ?? '')), '/');
  $username = trim((string)($state['username'] ?? ''));
  $password = (string)($state['_password'] ?? '');
  if ($base_url === '' || $username === '' || $password === '')
  {
    throw new RuntimeException('Für die WebDAV-Verbindung fehlen Nextcloud-Adresse oder Zugangsdaten.');
  }

  $selected = isset($state['directory_selected']) && is_array($state['directory_selected'])
    ? array_values(array_unique(array_map(function($path) { return trim((string)$path, '/'); }, $state['directory_selected'])))
    : array();
  $selected_ids = isset($state['directory_selected_fileids']) && is_array($state['directory_selected_fileids'])
    ? $state['directory_selected_fileids']
    : array();
  $selected = array_values(array_filter($selected, function($path) { return $path !== ''; }));
  if (!$selected) throw new RuntimeException('Bitte mindestens ein Nextcloud-Verzeichnis auswählen.');

  $roots = array();
  foreach ($selected as $path)
  {
    $fileid = isset($selected_ids[$path]) ? (int)$selected_ids[$path] : 0;
    if ($fileid < 1) throw new RuntimeException('Für ein ausgewähltes Nextcloud-Verzeichnis fehlt die eindeutige Datei-ID.');
    $roots[] = array(
      'fileid'=>$fileid,
      'display_name'=>basename($path),
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

  $config = array(
    'source_mode'=>'webdav-placeholder',
    'transport'=>'webdav',
    'nextcloud_url'=>$base_url,
    'access_user'=>$username,
    'nextcloud_access_user'=>$username,
    'gallery_root'=>$gallery_root,
    'roots'=>$roots,
    'state_dir'=>'',
    'status_file'=>'',
    'piwigo_auth'=>'connection-scoped',
    'api_enabled'=>$api_enabled,
  );

  foreach (array('product'=>'nextcloud_product', 'version'=>'nextcloud_version') as $state_key=>$config_key)
  {
    $value = trim((string)($state[$state_key] ?? ''));
    if ($value !== '') $config[$config_key] = $value;
  }

  $secret_payload = json_encode(array(
    'v'=>3,
    'piwigo_user'=>$fallback_user,
    'piwigo_password'=>$fallback_password,
    'api_key_id'=>$api_key_id,
    'api_key_secret'=>$api_key_secret,
    'nextcloud_user'=>$username,
    'nextcloud_password'=>$password,
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($secret_payload)) throw new RuntimeException('WebDAV-Zugangsdaten konnten nicht serialisiert werden.');

  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json)) throw new RuntimeException('WebDAV-Konfiguration konnte nicht serialisiert werden.');

  bratonien_tools_nc_connector_ensure_table();
  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  $connection_key = 'webdav-'.bin2hex(random_bytes(12));
  $secret_blob = bratonien_tools_nc_connector_encrypt_secret($secret_payload);

  pwg_query("INSERT INTO `$table` (connection_key,name,adapter,enabled,config_json,secret_blob,created,updated) VALUES ('"
    .pwg_db_real_escape_string($connection_key)."','"
    .pwg_db_real_escape_string($name)."','remote',1,'"
    .pwg_db_real_escape_string($config_json)."','"
    .pwg_db_real_escape_string($secret_blob)."','"
    .pwg_db_real_escape_string($now)."','"
    .pwg_db_real_escape_string($now)."')");

  $id = (int)pwg_db_insert_id();
  if ($id < 1) throw new RuntimeException('Die WebDAV-Verbindung konnte nicht eindeutig angelegt werden.');

  $config['state_dir'] = '/var/lib/bratonien-tools/nc-connector/connection-'.$id;
  $config['status_file'] = $config['state_dir'].'/connector-status.json';
  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json))
  {
    pwg_query("DELETE FROM `$table` WHERE id=".$id." LIMIT 1");
    throw new RuntimeException('Die WebDAV-Verbindung konnte nach dem Anlegen nicht serialisiert werden.');
  }
  pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($config_json)."' WHERE id=".$id." LIMIT 1");

  return array(
    'connection_id'=>$id,
    'message'=>'WebDAV-Verbindung wurde angelegt.',
  );
}
