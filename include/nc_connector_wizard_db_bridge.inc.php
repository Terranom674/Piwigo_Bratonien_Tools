<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_wizard_apply_known_database_profile(array &$state)
{
  if (empty($state['scan_ok']) || trim((string)($state['base_url'] ?? '')) === '') return false;

  $base_host = strtolower(trim((string)parse_url((string)$state['base_url'], PHP_URL_HOST)));

  foreach (bratonien_tools_nc_connector_connections() as $candidate)
  {
    $config = isset($candidate['config']) && is_array($candidate['config']) ? $candidate['config'] : array();
    $candidate_url = trim((string)($config['nextcloud_url'] ?? ''));
    $match = false;

    if ($candidate_url !== '')
    {
      try
      {
        $match = bratonien_tools_nc_wizard_normalize_url($candidate_url) === (string)$state['base_url'];
      }
      catch (Throwable $ignored)
      {
        $match = false;
      }
    }
    elseif ($base_host !== '')
    {
      $match = strtolower(trim((string)($config['host'] ?? ''))) === $base_host;
    }

    if (!$match) continue;

    $connection = bratonien_tools_nc_connector_connection((int)$candidate['id'], true);
    if (!$connection || !is_array($connection['config'] ?? null)) continue;

    $known = $connection['config'];
    $db_user = trim((string)($known['user'] ?? ''));
    if ($db_user === '') continue;

    $db_password = '';
    try
    {
      $plain = bratonien_tools_nc_connector_decrypt_secret($connection['secret_blob'] ?? '');
      $decoded = json_decode($plain, true);
      $db_password = is_array($decoded) && array_key_exists('db_password', $decoded)
        ? (string)$decoded['db_password']
        : (string)$plain;
    }
    catch (Throwable $ignored)
    {
      $db_password = '';
    }
    if ($db_password === '') continue;

    $state['db_host'] = trim((string)($known['host'] ?? $state['db_host']));
    $state['db_port'] = trim((string)($known['port'] ?? $state['db_port']));
    $state['db_database'] = trim((string)($known['database'] ?? $state['db_database']));
    $state['db_user'] = $db_user;
    $state['_db_password'] = $db_password;
    $state['db_password_set'] = true;
    $state['source_view'] = trim((string)($known['source_view'] ?? $state['source_view']));
    $state['activity_view'] = trim((string)($known['activity_view'] ?? $state['activity_view']));
    $state['gallery_root'] = trim((string)($known['gallery_root'] ?? $state['gallery_root']));
    $state['storages'] = isset($known['storages']) && is_array($known['storages']) ? $known['storages'] : array();
    $state['storage_candidates'] = $state['storages'];
    $state['technical_source'] = 'Gespeicherte Reader-Verbindung wird erneut geprüft';
    $state['technical_error'] = '';
    return true;
  }

  return false;
}

function bratonien_tools_nc_wizard_prepare_directory_stage(array &$state, array $known_storages)
{
  if ((string)($state['technical_stage'] ?? '') === 'database_details') return;

  $known_by_storage = array();
  foreach ($known_storages as $known)
  {
    $storage_id = trim((string)($known['storage_id'] ?? ''));
    $mount = rtrim(trim((string)($known['local_mount'] ?? '')), '/');
    if ($storage_id === '' || $mount === '') continue;
    if (!isset($known_by_storage[$storage_id])) $known_by_storage[$storage_id] = $known;
  }

  $candidates = isset($state['storage_candidates']) && is_array($state['storage_candidates']) ? $state['storage_candidates'] : array();
  $all_mapped = !empty($candidates);

  foreach ($candidates as &$candidate)
  {
    $storage_id = trim((string)($candidate['storage_id'] ?? ''));
    $known = isset($known_by_storage[$storage_id]) ? $known_by_storage[$storage_id] : null;
    if (is_array($known))
    {
      $candidate['source_prefix'] = trim((string)($known['source_prefix'] ?? $candidate['source_prefix'] ?? ''), '/');
      $candidate['local_mount'] = rtrim(trim((string)($known['local_mount'] ?? '')), '/');
    }

    $mount = trim((string)($candidate['local_mount'] ?? ''));
    if ($mount === '' || !is_dir($mount) || !is_readable($mount)) $all_mapped = false;
    $candidate['include_prefix'] = '';
  }
  unset($candidate);

  $state['storage_candidates'] = $candidates;
  $state['storages'] = array();
  $state['technical_complete'] = false;
  $state['technical_stage'] = 'mounts';
  $state['technical_source'] = $all_mapped
    ? 'Datenzugriff und Speicherzuordnung erkannt; Verzeichnisauswahl offen'
    : 'Datenzugriff erkannt; Speicherzuordnung muss bestätigt werden';
  $state['directory_selection_ready'] = $all_mapped;
}

function bratonien_tools_nc_wizard_finish_data_access_for_selection(array &$state, array $known_storages)
{
  bratonien_tools_nc_wizard_finish_data_access($state);
  bratonien_tools_nc_wizard_prepare_directory_stage($state, $known_storages);
}

function bratonien_tools_nc_wizard_scan_with_known_database()
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

  if ($username === '' || $password === '') throw new RuntimeException('Nextcloud-Benutzer und Passwort werden für den Scan benötigt.');

  $base_url = '';
  $status_data = null;
  foreach (bratonien_tools_nc_wizard_candidate_urls($host_input) as $candidate_url)
  {
    try
    {
      $response = bratonien_tools_nc_wizard_http($candidate_url.'/status.php');
      if ($response['status'] < 200 || $response['status'] >= 300) continue;
      $candidate_status = json_decode($response['body'], true);
      if (!is_array($candidate_status) || empty($candidate_status['installed'])) continue;
      $base_url = $candidate_url;
      $status_data = $candidate_status;
      break;
    }
    catch (Throwable $ignored)
    {
    }
  }

  if ($base_url === '' || !is_array($status_data)) throw new RuntimeException('Unter dieser Adresse konnte keine Nextcloud erreicht werden. HTTP und HTTPS wurden automatisch geprüft.');

  $user_response = bratonien_tools_nc_wizard_http(
    $base_url.'/ocs/v2.php/cloud/user?format=json',
    $username,
    $password,
    array('OCS-APIRequest: true')
  );
  if ($user_response['status'] === 401 || $user_response['status'] === 403) throw new RuntimeException('Nextcloud hat Benutzername oder Passwort abgelehnt.');
  if ($user_response['status'] < 200 || $user_response['status'] >= 300) throw new RuntimeException('Nextcloud ist erreichbar, aber die Anmeldung konnte nicht geprüft werden.');

  $user_data = bratonien_tools_nc_wizard_ocs_data($user_response['body']);
  $users = array();
  $can_list_users = false;
  try
  {
    $users_response = bratonien_tools_nc_wizard_http(
      $base_url.'/ocs/v2.php/cloud/users?format=json',
      $username,
      $password,
      array('OCS-APIRequest: true')
    );
    if ($users_response['status'] >= 200 && $users_response['status'] < 300)
    {
      $users_data = bratonien_tools_nc_wizard_ocs_data($users_response['body']);
      if (isset($users_data['users']) && is_array($users_data['users']))
      {
        $users = array_values(array_filter(array_map('strval', $users_data['users'])));
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
        $can_list_users = true;
      }
    }
  }
  catch (Throwable $ignored)
  {
  }

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
    'users'=>$users,
    'can_list_users'=>$can_list_users,
    'showcase_user'=>'',
    'connection_name'=>$url_host !== '' ? $url_host : 'Nextcloud',
    'scan_message'=>'Nextcloud wurde erkannt und der Benutzerzugriff wurde bestätigt.',
    '_password'=>$password,
    'api_status'=>'pending','api_username'=>'','api_error'=>'',
    'db_host'=>strtolower($url_host),'db_port'=>'5432','db_database'=>'nextcloud','db_user'=>'','db_password_set'=>false,'_db_password'=>'',
    'source_view'=>'piwigo_showcase_sources','activity_view'=>'piwigo_showcase_activity',
    'gallery_root'=>rtrim(PHPWG_ROOT_PATH, '/').'/galleries/nextcloud',
    'storages'=>array(),'storage_candidates'=>array(),
    'technical_stage'=>'auto_check','technical_source'=>'Automatische Prüfung','technical_error'=>'','technical_complete'=>false,
    'directory_selection_ready'=>false,
  ));

  if (!bratonien_tools_nc_wizard_apply_known_database_profile($state))
  {
    $state['technical_stage'] = 'database_details';
    $state['technical_source'] = 'Keine bekannte Reader-Verbindung gefunden';
    $state['technical_error'] = 'Für diese Nextcloud ist noch keine bekannte Datenbank-Reader-Verbindung gespeichert. Die Nextcloud-Anmeldedaten werden nicht als PostgreSQL-Zugang verwendet.';
    bratonien_tools_nc_wizard_store($state);
    return array('message'=>'Nextcloud wurde gefunden. Für den Datenzugriff wird eine separate Reader-Verbindung benötigt.');
  }

  $known_storages = isset($state['storages']) && is_array($state['storages']) ? $state['storages'] : array();
  try
  {
    bratonien_tools_nc_wizard_finish_data_access_for_selection($state, $known_storages);
  }
  catch (Throwable $e)
  {
    $state['technical_complete'] = false;
    $state['technical_stage'] = 'database_details';
    $state['technical_error'] = $e->getMessage();
  }
  bratonien_tools_nc_wizard_store($state);

  if ($state['technical_stage'] === 'database_details') return array('message'=>'Nextcloud wurde gefunden. Die bekannte Reader-Verbindung konnte noch nicht vollständig bestätigt werden.');
  return array('message'=>'Nextcloud und Datenzugriff wurden bestätigt. Jetzt können die gewünschten Verzeichnisse gewählt werden.');
}

function bratonien_tools_nc_wizard_save_technical_with_known_database()
{
  $state = bratonien_tools_nc_wizard_state();
  if (empty($state['scan_ok'])) throw new RuntimeException('Bitte zuerst Nextcloud erfolgreich scannen.');
  if (trim((string)($state['db_user'] ?? '')) === '' || (string)($state['_db_password'] ?? '') === '') bratonien_tools_nc_wizard_apply_known_database_profile($state);
  if (trim((string)($state['db_user'] ?? '')) === '' || (string)($state['_db_password'] ?? '') === '') throw new RuntimeException('Für diese Nextcloud sind noch keine Datenbank-Reader-Zugangsdaten bekannt. Nextcloud-Benutzer und -Passwort werden dafür bewusst nicht verwendet.');

  $known_storages = isset($state['storages']) && is_array($state['storages']) ? $state['storages'] : array();
  $state['db_host'] = trim((string)($_POST['nc_wizard_db_host'] ?? $state['db_host']));
  $state['db_port'] = (string)max(1, min(65535, (int)($_POST['nc_wizard_db_port'] ?? $state['db_port'])));
  $state['db_database'] = trim((string)($_POST['nc_wizard_db_database'] ?? $state['db_database']));
  if ($state['db_host'] === '' || $state['db_database'] === '') throw new RuntimeException('Die Adresse der Datenbank ist noch unvollständig.');

  try
  {
    bratonien_tools_nc_wizard_finish_data_access_for_selection($state, $known_storages);
    bratonien_tools_nc_wizard_store($state);
    return array('message'=>'Datenzugriff wurde erfolgreich geprüft. Jetzt können die gewünschten Verzeichnisse gewählt werden.');
  }
  catch (Throwable $e)
  {
    $state['technical_error'] = $e->getMessage();
    $state['technical_stage'] = 'database_details';
    bratonien_tools_nc_wizard_store($state);
    throw $e;
  }
}

function bratonien_tools_nc_wizard_directory_options(array $state)
{
  if (empty($state['scan_ok']) || trim((string)($state['db_user'] ?? '')) === '' || (string)($state['_db_password'] ?? '') === '') return array();

  $config = array('host'=>$state['db_host'],'port'=>$state['db_port'],'database'=>$state['db_database'],'user'=>$state['db_user']);
  $source_view = bratonien_tools_nc_connector_view_name($state['source_view']);
  $rows = bratonien_tools_nc_connector_psql($config, (string)$state['_db_password'], "SELECT DISTINCT storage_id::text || E'\\t' || COALESCE(source_path, '') FROM ".$source_view." ORDER BY 1");

  $paths = array();
  foreach (preg_split('/\r\n|\r|\n/', trim($rows)) as $line)
  {
    if ($line === '') continue;
    $parts = explode("\t", $line, 2);
    if (count($parts) !== 2) continue;
    $storage_id = trim($parts[0]);
    $source_path = trim($parts[1], '/');
    if ($storage_id === '') continue;
    if (!isset($paths[$storage_id])) $paths[$storage_id] = array();
    $paths[$storage_id][] = $source_path;
  }

  $result = array();
  foreach ((array)($state['storage_candidates'] ?? array()) as $index=>$candidate)
  {
    $storage_id = trim((string)($candidate['storage_id'] ?? ''));
    $mapping_prefix = trim((string)($candidate['source_prefix'] ?? ''), '/');
    $options = array(''=>'Stammverzeichnis');

    foreach (($paths[$storage_id] ?? array()) as $source_path)
    {
      $relative = $source_path;
      if ($mapping_prefix !== '')
      {
        if ($source_path === $mapping_prefix) $relative = '';
        elseif (strpos($source_path, $mapping_prefix.'/') === 0) $relative = substr($source_path, strlen($mapping_prefix) + 1);
        else continue;
      }
      $relative = trim($relative, '/');
      if ($relative === '') continue;
      $segments = explode('/', $relative);
      $current = '';
      foreach ($segments as $segment)
      {
        if ($segment === '') continue;
        $current = $current === '' ? $segment : $current.'/'.$segment;
        $options[$current] = $current;
      }
    }

    if (count($options) > 1)
    {
      $root = $options[''];
      unset($options['']);
      natcasesort($options);
      $options = array(''=>$root) + $options;
    }

    $result[] = array(
      'index'=>(int)$index,
      'storage_id'=>$storage_id,
      'mount'=>(string)($candidate['local_mount'] ?? ''),
      'ready'=>trim((string)($candidate['local_mount'] ?? '')) !== '',
      'options'=>$options,
    );
  }
  return $result;
}

function bratonien_tools_nc_wizard_save_mounts_with_directories()
{
  $state = bratonien_tools_nc_wizard_state();
  $candidates = isset($state['storage_candidates']) && is_array($state['storage_candidates']) ? $state['storage_candidates'] : array();
  if (!$candidates) throw new RuntimeException('Es wurden noch keine Speicherorte erkannt.');

  $mounts = isset($_POST['nc_wizard_storage_mount']) && is_array($_POST['nc_wizard_storage_mount']) ? $_POST['nc_wizard_storage_mount'] : array();
  $directories = isset($_POST['nc_wizard_directory']) && is_array($_POST['nc_wizard_directory']) ? $_POST['nc_wizard_directory'] : array();
  $storages = array();

  foreach ($candidates as $index=>$candidate)
  {
    $mount = rtrim(trim((string)($candidate['local_mount'] ?? '')), '/');
    if ($mount === '') $mount = rtrim(trim((string)($mounts[$index] ?? '')), '/');
    if ($mount === '' || $mount[0] !== '/') throw new RuntimeException('Für einen Speicherort fehlt ein gültiger lokaler Pfad.');
    if (!is_dir($mount) || !is_readable($mount)) throw new RuntimeException('Der angegebene Speicherort ist nicht vorhanden oder nicht lesbar: '.$mount);

    $selected = isset($directories[$index]) && is_array($directories[$index]) ? $directories[$index] : array();
    $normalized = array();
    foreach ($selected as $directory)
    {
      $directory = trim((string)$directory, '/');
      if ($directory === '') continue;
      if ($directory[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $directory)) throw new RuntimeException('Ungültige Verzeichnisauswahl.');
      $normalized[$directory] = true;
    }
    if (!$normalized) $normalized[''] = true;

    foreach (array_keys($normalized) as $include_prefix)
    {
      $storages[] = array(
        'storage_id'=>(string)($candidate['storage_id'] ?? ''),
        'source_prefix'=>trim((string)($candidate['source_prefix'] ?? ''), '/'),
        'local_mount'=>$mount,
        'include_prefix'=>$include_prefix,
      );
    }
  }

  $state['storages'] = $storages;
  $state['technical_complete'] = true;
  $state['technical_stage'] = 'ready';
  $state['technical_error'] = '';
  $state['directory_selection_ready'] = false;
  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'Verzeichnisauswahl wurde übernommen.');
}

function bratonien_tools_nc_wizard_finish_with_directories()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 4 || empty($state['technical_complete']) || trim((string)$state['showcase_user']) === '') throw new RuntimeException('Der Assistent ist noch nicht vollständig.');

  if (array_key_exists('nc_wizard_fallback_user', $_POST)) $state['_fallback_user'] = trim((string)$_POST['nc_wizard_fallback_user']);
  if (array_key_exists('nc_wizard_fallback_password', $_POST)) $state['_fallback_password'] = (string)$_POST['nc_wizard_fallback_password'];
  bratonien_tools_nc_wizard_store($state);

  $fallback_user = trim((string)$state['_fallback_user']);
  $fallback_password = (string)$state['_fallback_password'];
  if (($fallback_user === '') !== ($fallback_password === '')) throw new RuntimeException('Fallback-Benutzer und Fallback-Passwort müssen entweder beide angegeben oder beide leer gelassen werden.');
  if ($state['api_status'] !== 'ok' && $fallback_user === '') throw new RuntimeException('Da die Piwigo-API übersprungen wurde, ist ein Fallback-Zugang erforderlich.');

  $mapping_lines = array();
  foreach ((array)$state['storages'] as $storage)
  {
    $key = (string)$storage['storage_id'].'|'.trim((string)$storage['source_prefix'], '/').'|'.(string)$storage['local_mount'];
    $mapping_lines[$key] = (string)$storage['storage_id'].' | '.trim((string)$storage['source_prefix'], '/').' | '.(string)$storage['local_mount'];
  }

  $_POST['nc_name']=(string)$state['connection_name'];$_POST['nc_host']=(string)$state['db_host'];$_POST['nc_port']=(string)$state['db_port'];$_POST['nc_database']=(string)$state['db_database'];$_POST['nc_user']=(string)$state['db_user'];$_POST['nc_db_password']=(string)$state['_db_password'];
  $_POST['nc_source_view']=(string)$state['source_view'];$_POST['nc_activity_view']=(string)$state['activity_view'];$_POST['nc_gallery_root']=(string)$state['gallery_root'];$_POST['nc_storages']=implode("\n",array_values($mapping_lines));
  $_POST['nc_quiet_seconds']='120';$_POST['nc_max_wait_seconds']='900';$_POST['nc_full_sync_seconds']='86400';$_POST['nc_piwigo_user']=$fallback_user;$_POST['nc_piwigo_password']=$fallback_password;
  $_POST['nc_nextcloud_url']=(string)$state['base_url'];$_POST['nc_showcase_user']=(string)$state['showcase_user'];$_POST['nc_access_user']=(string)$state['username'];$_POST['nc_product']=(string)$state['product'];$_POST['nc_version']=(string)$state['version'];$_POST['nc_api_validated']=$state['api_status']==='ok'?'1':'0';

  $result = bratonien_tools_nc_connector_create_local_api_first();
  $connection_id = (int)($result['connection_id'] ?? 0);
  if ($connection_id < 1) throw new RuntimeException('Die neue Verbindung konnte nicht gespeichert werden.');

  $connection = bratonien_tools_nc_connector_connection($connection_id, false);
  if (!$connection) throw new RuntimeException('Die neue Verbindung konnte nach dem Anlegen nicht gelesen werden.');
  $config = $connection['config'];
  $config['storages'] = array_values($state['storages']);
  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json)) throw new RuntimeException('Die Verzeichnisauswahl konnte nicht serialisiert werden.');
  $table = bratonien_tools_nc_connector_table();
  pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($config_json)."' WHERE id=".$connection_id." LIMIT 1");

  try
  {
    if ($state['api_status'] === 'ok') bratonien_tools_nc_api_credentials_store((string)$state['_api_key_id'], (string)$state['_api_key_secret']);
  }
  catch (Throwable $e)
  {
    pwg_query("DELETE FROM `$table` WHERE id=".$connection_id." LIMIT 1");
    throw new RuntimeException('Die Verbindung wurde nicht übernommen, weil der geprüfte API-Zugang nicht gespeichert werden konnte: '.$e->getMessage());
  }

  unset($_SESSION['bratonien_nc_wizard']);
  unset($result['connection_id']);
  $result['message'] = 'Verbindung wurde vollständig durch den Assistenten angelegt. '.$result['message'];
  return $result;
}
