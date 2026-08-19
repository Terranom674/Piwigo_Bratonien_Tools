<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Link a WebDAV successor with the exact legacy connection selected for migration.
 * The caller must execute this inside the same transaction that creates the successor.
 */
function bratonien_tools_nc_connector_pair_migration_fallback($legacy_id, $webdav_id, array &$webdav_config, $now)
{
  $legacy_id = (int)$legacy_id;
  $webdav_id = (int)$webdav_id;
  if ($legacy_id < 1 || $webdav_id < 1 || $legacy_id === $webdav_id)
  {
    throw new RuntimeException('Die Migrationszuordnung ist ungueltig.');
  }

  $legacy = bratonien_tools_nc_connector_connection($legacy_id, false);
  if (!$legacy || (string)$legacy['adapter'] !== 'local')
  {
    throw new RuntimeException('Die ausgewaehlte Legacy-Verbindung fuer die Migration ist nicht mehr verfuegbar.');
  }

  $legacy_config = isset($legacy['config']) && is_array($legacy['config']) ? $legacy['config'] : array();
  $webdav_config['migration'] = array(
    'role'=>'webdav-primary-candidate',
    'legacy_fallback_connection_id'=>$legacy_id,
    'fallback_policy'=>'keep-running',
    'paired_at'=>(string)$now,
    'cutover_state'=>'parallel',
  );
  $legacy_config['migration'] = array(
    'role'=>'legacy-fallback',
    'webdav_successor_connection_id'=>$webdav_id,
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
  pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($legacy_json)."', updated='".pwg_db_real_escape_string((string)$now)."' WHERE id=".$legacy_id." AND adapter='local' LIMIT 1");

  return $legacy_id;
}

/**
 * Create or update a WebDAV-backed connector from the user-facing wizard.
 *
 * Remote connections are updated in place. A legacy connection is paired only
 * when the wizard was explicitly opened in migration mode for that connection.
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
  if (!$selected) throw new RuntimeException('Bitte mindestens ein Nextcloud-Verzeichnis auswaehlen.');

  $roots = array();
  foreach ($selected as $path)
  {
    $fileid = isset($selected_ids[$path]) ? (int)$selected_ids[$path] : 0;
    if ($fileid < 1) throw new RuntimeException('Fuer ein ausgewaehltes Nextcloud-Verzeichnis fehlt die eindeutige Datei-ID.');
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
  $migrating_legacy = $editing_connection
    && (string)$editing_connection['adapter'] === 'local'
    && $editing_mode === 'migrate';

  if ($editing_mode === 'migrate' && !$migrating_legacy)
  {
    throw new RuntimeException('Die fuer die Migration ausgewaehlte Legacy-Verbindung ist nicht mehr verfuegbar.');
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
    'parallel_test'=>true,
    'piwigo_auth'=>'connection-scoped',
    'api_enabled'=>$api_enabled,
  );

  if ($editing_remote)
  {
    $old_config = is_array($editing_connection['config'] ?? null) ? $editing_connection['config'] : array();
    foreach (array('state_dir','status_file','parallel_gallery_root','source_fingerprint','runtime','migration') as $preserve)
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
      'message'=>'WebDAV-Verbindung #'.$editing_id.' wurde gespeichert. Der naechste Connector-Lauf verwendet die neuen Einstellungen.',
    );
  }

  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json)) throw new RuntimeException('WebDAV-Konfiguration konnte nicht serialisiert werden.');

  $connection_key = 'webdav-'.bin2hex(random_bytes(12));
  $legacy_fallback_id = null;
  $id = 0;

  pwg_query('START TRANSACTION');
  try
  {
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
    if ($migrating_legacy)
    {
      $legacy_fallback_id = bratonien_tools_nc_connector_pair_migration_fallback($editing_id, $id, $config, $now);
    }

    $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($config_json))
    {
      throw new RuntimeException('Die WebDAV-Verbindung konnte nach dem Anlegen nicht serialisiert werden.');
    }
    pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($config_json)."' WHERE id=".$id." AND adapter='remote' LIMIT 1");
    pwg_query('COMMIT');
  }
  catch (Throwable $e)
  {
    pwg_query('ROLLBACK');
    throw $e;
  }

  unset($_SESSION['bratonien_nc_wizard']);

  if ($legacy_fallback_id !== null)
  {
    return array(
      'connection_id'=>$id,
      'legacy_fallback_connection_id'=>$legacy_fallback_id,
      'message'=>'WebDAV-Nachfolger wurde angelegt. Die bestehende lokale Verbindung #'.$legacy_fallback_id.' bleibt als Fallback erhalten.',
    );
  }

  return array(
    'connection_id'=>$id,
    'message'=>'WebDAV-Verbindung wurde angelegt.',
  );
}
