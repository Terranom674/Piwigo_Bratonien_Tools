<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_wizard_scan_webdav_first()
{
  $state = bratonien_tools_nc_wizard_state();
  $host_input = trim((string)($_POST['nc_wizard_host'] ?? $state['host_input']));
  $username = trim((string)($_POST['nc_wizard_user'] ?? $state['username']));
  $password = array_key_exists('nc_wizard_password', $_POST) ? (string)$_POST['nc_wizard_password'] : (string)$state['_password'];

  $state['step'] = 1;
  $state['host_input'] = $host_input;
  $state['username'] = $username;
  $state['_password'] = $password;
  bratonien_tools_nc_wizard_store($state);

  if ($username === '' || $password === '')
  {
    throw new RuntimeException('Nextcloud-Benutzer und Passwort werden für den Scan benötigt.');
  }

  $base_url = '';
  $status_data = null;
  foreach (bratonien_tools_nc_wizard_candidate_urls($host_input) as $candidate_url)
  {
    try
    {
      $response = bratonien_tools_nc_transport_http($candidate_url.'/status.php');
      if ($response['status'] < 200 || $response['status'] >= 300) continue;
      $candidate_status = json_decode($response['body'], true);
      if (!is_array($candidate_status) || empty($candidate_status['installed'])) continue;
      $base_url = $candidate_url;
      $status_data = $candidate_status;
      break;
    }
    catch (Throwable $ignored) {}
  }

  if ($base_url === '' || !is_array($status_data))
  {
    throw new RuntimeException('Unter dieser Adresse konnte keine Nextcloud erreicht werden. HTTP und HTTPS wurden automatisch geprüft.');
  }

  $user_response = bratonien_tools_nc_transport_http(
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
    throw new RuntimeException('Nextcloud ist erreichbar, aber die Anmeldung konnte nicht geprüft werden.');
  }

  $user_data = bratonien_tools_nc_wizard_ocs_data($user_response['body']);
  $resolved_username = trim((string)($user_data['id'] ?? $username));
  if ($resolved_username === '') $resolved_username = $username;
  $url_host = (string)parse_url($base_url, PHP_URL_HOST);

  $state = array_merge($state, array(
    'step'=>2,
    'scan_ok'=>true,
    'base_url'=>$base_url,
    'host_input'=>$host_input,
    'username'=>$resolved_username,
    'display_name'=>(string)($user_data['display-name'] ?? $user_data['displayname'] ?? ''),
    'version'=>(string)($status_data['versionstring'] ?? $status_data['version'] ?? ''),
    'product'=>(string)($status_data['productname'] ?? 'Nextcloud'),
    'users'=>array(),
    'can_list_users'=>false,
    'showcase_user'=>$resolved_username,
    'connection_name'=>$url_host !== '' ? $url_host : 'Nextcloud WebDAV',
    'scan_message'=>'Nextcloud und WebDAV-Zugriff wurden bestätigt.',
    '_password'=>$password,
    'source_mode'=>'webdav-placeholder',
    'transport'=>'webdav',
    'gallery_root'=>rtrim(PHPWG_ROOT_PATH, '/').'/galleries',
    'storages'=>array(),
    'storage_candidates'=>array(),
    'roots'=>array(),
    'technical_stage'=>'mounts',
    'technical_source'=>'WebDAV',
    'technical_error'=>'',
    'technical_complete'=>false,
    'directory_selection_ready'=>true,
    'directory_path'=>'',
    'directory_parent'=>'',
    'directory_children'=>array(),
    'directory_current_fileid'=>0,
    'directory_fileids'=>array(),
    'directory_selected'=>array(),
    'directory_selected_fileids'=>array(),
    'database_prompted'=>false,
    'mount_prompted'=>false,
    'api_status'=>'pending',
    'api_username'=>'',
    'api_error'=>'',
  ));

  bratonien_tools_nc_transport_refresh_directory_state($state, '');
  bratonien_tools_nc_wizard_store($state);

  return array('message'=>'Nextcloud und WebDAV wurden bestätigt. Jetzt können die Verzeichnisse des angemeldeten Benutzers ausgewählt werden.');
}

function bratonien_tools_nc_wizard_save_sources_dispatch()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((string)($state['source_mode'] ?? '') !== 'webdav-placeholder')
  {
    return bratonien_tools_nc_wizard_save_mounts_server_side();
  }

  if ((int)($state['step'] ?? 0) !== 2 || empty($state['directory_selection_ready']))
  {
    throw new RuntimeException('Die Verzeichnisauswahl ist in diesem Fenster nicht verfügbar.');
  }

  $selected = isset($state['directory_selected']) && is_array($state['directory_selected'])
    ? array_values(array_unique(array_map(function($path) { return trim((string)$path, '/'); }, $state['directory_selected'])))
    : array();
  if (!$selected)
  {
    throw new RuntimeException('Bitte mindestens ein Nextcloud-Verzeichnis auswählen.');
  }

  $selected_ids = isset($state['directory_selected_fileids']) && is_array($state['directory_selected_fileids'])
    ? $state['directory_selected_fileids']
    : array();
  $roots = array();
  foreach ($selected as $path)
  {
    $fileid = (int)($selected_ids[$path] ?? 0);
    if ($fileid < 1)
    {
      throw new RuntimeException('Für eine Auswahl fehlt die eindeutige Nextcloud-Datei-ID. Bitte das Verzeichnis erneut auswählen.');
    }
    $display_name = $path === ''
      ? (trim((string)($state['display_name'] ?? '')) !== '' ? trim((string)$state['display_name']) : (string)$state['username'])
      : basename($path);
    $roots[] = array(
      'fileid'=>$fileid,
      'display_name'=>$display_name,
      'webdav_path'=>$path,
    );
  }

  $state['roots'] = $roots;
  $state['technical_complete'] = true;
  $state['technical_stage'] = 'ready';
  $state['technical_source'] = 'WebDAV-Verzeichnisse ausgewählt';
  $state['technical_error'] = '';
  $state['directory_selection_ready'] = false;

  if ((string)($state['editing_mode'] ?? '') === 'migrate')
  {
    $editing_id = (int)($state['editing_connection_id'] ?? 0);
    $connection = $editing_id > 0 ? bratonien_tools_nc_connector_connection($editing_id, true) : null;
    if (!$connection || (string)$connection['adapter'] !== 'local')
    {
      throw new RuntimeException('Die zu migrierende Legacy-Verbindung ist nicht mehr verfügbar.');
    }

    $migration = bratonien_tools_nc_connector_migration_state($connection);
    if (empty($migration['ready']))
    {
      throw new RuntimeException('Die WebDAV-Migration kann noch nicht abgeschlossen werden. Es fehlen: '.implode(', ', $migration['missing']).'.');
    }

    $credentials = bratonien_tools_nc_connector_scoped_secret($connection);
    $state['_api_key_id'] = (string)($credentials['api_key_id'] ?? '');
    $state['_api_key_secret'] = (string)($credentials['api_key_secret'] ?? '');
    $state['api_status'] = !empty($migration['api_available']) ? 'ok' : 'skipped';
    $state['api_error'] = '';
    $state['step'] = 4;
    bratonien_tools_nc_wizard_store($state);

    return array('message'=>'WebDAV-Quelle wurde übernommen. Mit „Migration starten“ wird jetzt der WebDAV-Nachfolger angelegt; die Legacy-Verbindung bleibt als Fallback erhalten.');
  }

  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'WebDAV-Verzeichnisse wurden übernommen. Die Verbindung ist für den nächsten Einrichtungsschritt vorbereitet.');
}

function bratonien_tools_nc_wizard_finish_dispatch()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((string)($state['source_mode'] ?? '') !== 'webdav-placeholder')
  {
    return bratonien_tools_nc_wizard_finish_generic_scope();
  }

  if ((int)($state['step'] ?? 0) !== 4 || empty($state['technical_complete']))
  {
    throw new RuntimeException('Der Assistent ist noch nicht vollständig.');
  }

  if (array_key_exists('nc_wizard_fallback_user', $_POST)) $state['_fallback_user'] = trim((string)$_POST['nc_wizard_fallback_user']);
  if (array_key_exists('nc_wizard_fallback_password', $_POST)) $state['_fallback_password'] = (string)$_POST['nc_wizard_fallback_password'];
  bratonien_tools_nc_wizard_store($state);

  $fallback_user = trim((string)($state['_fallback_user'] ?? ''));
  $fallback_password = (string)($state['_fallback_password'] ?? '');
  if (($fallback_user === '') !== ($fallback_password === ''))
  {
    throw new RuntimeException('Fallback-Benutzer und Fallback-Passwort müssen entweder beide angegeben oder beide leer gelassen werden.');
  }
  if (($state['api_status'] ?? '') !== 'ok' && $fallback_user === '')
  {
    throw new RuntimeException('Da die Piwigo-API übersprungen wurde, ist für diese Verbindung ein Fallback-Zugang erforderlich.');
  }

  if ($fallback_user !== '')
  {
    try
    {
      bratonien_tools_nc_connector_validate_fallback_credentials($fallback_user, $fallback_password);
    }
    catch (Throwable $e)
    {
      throw new RuntimeException('Der Piwigo-Fallback wurde nicht gespeichert: '.$e->getMessage());
    }
  }

  $result = bratonien_tools_nc_connector_create_webdav_placeholder_from_wizard();
  unset($_SESSION['bratonien_nc_wizard']);
  return $result;
}
