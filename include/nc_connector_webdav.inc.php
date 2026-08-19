<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Return the only existing local connector as migration fallback.
 *
 * The 0.9.6.2 migration path is deliberately conservative: automatic pairing
 * only happens when there is exactly one local connection. Its enabled/state
 * flags are never changed here, so the proven local path keeps running while
 * WebDAV is introduced beside it.
 */
function bratonien_tools_nc_connector_single_local_fallback()
{
  bratonien_tools_nc_connector_ensure_table();
  $table = bratonien_tools_nc_connector_table();
  $result = pwg_query("SELECT id, enabled, takeover_state, config_json FROM `$table` WHERE adapter='local' ORDER BY id");
  $rows = array();

  while ($row = pwg_db_fetch_assoc($result))
  {
    $rows[] = $row;
    if (count($rows) > 1) return null;
  }

  if (count($rows) !== 1) return null;

  $row = $rows[0];
  $config = json_decode((string)$row['config_json'], true);
  if (!is_array($config)) $config = array();

  return array(
    'id'=>(int)$row['id'],
    'enabled'=>(bool)$row['enabled'],
    'takeover_state'=>(string)$row['takeover_state'],
    'config'=>$config,
  );
}

/**
 * Link the new WebDAV connection with the single existing local connection.
 *
 * This is metadata only. The legacy connection is explicitly left untouched
 * as an always-available fallback until a later, separately approved cutover.
 */
function bratonien_tools_nc_connector_pair_migration_fallback($webdav_id, array &$webdav_config, $now)
{
  $legacy = bratonien_tools_nc_connector_single_local_fallback();
  if (!$legacy) return null;

  $legacy_id = (int)$legacy['id'];
  if ($legacy_id < 1 || $legacy_id === (int)$webdav_id) return null;

  $webdav_config['migration'] = array(
    'role'=>'webdav-primary-candidate',
    'legacy_fallback_connection_id'=>$legacy_id,
    'fallback_policy'=>'keep-running',
    'paired_at'=>(string)$now,
    'cutover_state'=>'parallel',
  );

  $legacy_config = $legacy['config'];
  $legacy_config['migration'] = array(
    'role'=>'legacy-fallback',
    'webdav_successor_connection_id'=>(int)$webdav_id,
    'fallback_policy'=>'keep-running',
    'paired_at'=>(string)$now,
    'cutover_state'=>'parallel',
  );

  $legacy_json = json_encode($legacy_config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($legacy_json))
  {
    throw new RuntimeException('Die bestehende Verbindung konnte nicht als Migrations-Fallback markiert werden.');
  }

  $table = bratonien_tools_nc_connector_table();
  pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($legacy_json)."', updated='".pwg_db_real_escape_string((string)$now)."' WHERE id=".$legacy_id." LIMIT 1");

  return $legacy_id;
}

/**
 * Create a WebDAV-backed connector beside the existing local mode.
 *
 * Since 0.9.6.2, when exactly one local connection exists, the new WebDAV
 * connection is automatically paired with it for migration. The local
 * connection remains untouched and keeps running as fallback.
 */
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
    throw new RuntimeException('Für den WebDAV-Testpfad fehlen Nextcloud-Adresse oder Zugangsdaten.');
  }

  $selected = isset($state['directory_selected']) && is_array($state['directory_selected'])
    ? array_values(array_unique(array_map(function($path) { return trim((string)$path, '/'); }, $state['directory_selected'])))
    : array();
  $selected_ids = isset($state['directory_selected_fileids']) && is_array($state['directory_selected_fileids'])
    ? $state['directory_selected_fileids']
    : array();
  $selected = array_values(array_filter($selected, function($path) { return $path !== ''; }));
  if (!$selected) throw new RuntimeException('Bitte mindestens ein Nextcloud-Verzeichnis für den WebDAV-Testpfad auswählen.');

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
    'parallel_test'=>true,
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
    'db_password'=>'',
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

  pwg_query("INSERT INTO `$table` (connection_key,name,adapter,enabled,takeover_state,config_json,secret_blob,created,updated) VALUES ('"
    .pwg_db_real_escape_string($connection_key)."','"
    .pwg_db_real_escape_string($name)."','remote',0,'disabled','"
    .pwg_db_real_escape_string($config_json)."','"
    .pwg_db_real_escape_string($secret_blob)."','"
    .pwg_db_real_escape_string($now)."','"
    .pwg_db_real_escape_string($now)."')");

  $id = (int)pwg_db_insert_id();
  if ($id < 1) throw new RuntimeException('Die WebDAV-Testverbindung konnte nicht eindeutig angelegt werden.');

  try
  {
    $config['state_dir'] = '/var/lib/bratonien-tools/nc-connector/connection-'.$id;
    $config['status_file'] = $config['state_dir'].'/connector-status.json';
    $legacy_fallback_id = bratonien_tools_nc_connector_pair_migration_fallback($id, $config, $now);

    $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($config_json))
    {
      throw new RuntimeException('Die WebDAV-Testverbindung konnte nach dem Anlegen nicht serialisiert werden.');
    }
    pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($config_json)."' WHERE id=".$id." LIMIT 1");
  }
  catch (Throwable $e)
  {
    pwg_query("DELETE FROM `$table` WHERE id=".$id." LIMIT 1");
    throw $e;
  }

  if ($legacy_fallback_id !== null)
  {
    return array(
      'connection_id'=>$id,
      'legacy_fallback_connection_id'=>$legacy_fallback_id,
      'message'=>'WebDAV-Verbindung wurde parallel angelegt. Die bestehende lokale Verbindung #'.$legacy_fallback_id.' bleibt unverändert als laufender Fallback erhalten.',
    );
  }

  return array(
    'connection_id'=>$id,
    'message'=>'Parallele WebDAV-Verbindung wurde angelegt. Bestehende Verbindungen blieben unverändert.',
  );
}
