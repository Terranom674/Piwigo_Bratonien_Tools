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

  return array(
    'message'=>$is_remote
      ? 'Verbindung #'.$id.' wurde zum Bearbeiten geöffnet.'
      : 'Verbindung #'.$id.' wird im Assistenten auf WebDAV vorbereitet. Die bestehende Legacy-Verbindung bleibt dabei als Fallback erhalten.',
  );
}
