<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_wizard_apply_known_database_profile(array &$state)
{
  if (empty($state['scan_ok']) || trim((string)($state['base_url'] ?? '')) === '')
  {
    return false;
  }

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
    'api_status'=>'pending',
    'api_username'=>'',
    'api_error'=>'',
    'db_host'=>strtolower($url_host),
    'db_port'=>'5432',
    'db_database'=>'nextcloud',
    'db_user'=>'',
    'db_password_set'=>false,
    '_db_password'=>'',
    'source_view'=>'piwigo_showcase_sources',
    'activity_view'=>'piwigo_showcase_activity',
    'gallery_root'=>rtrim(PHPWG_ROOT_PATH, '/').'/galleries/nextcloud',
    'storages'=>array(),
    'storage_candidates'=>array(),
    'technical_stage'=>'auto_check',
    'technical_source'=>'Automatische Prüfung',
    'technical_error'=>'',
    'technical_complete'=>false,
  ));

  if (!bratonien_tools_nc_wizard_apply_known_database_profile($state))
  {
    $state['technical_stage'] = 'database_details';
    $state['technical_source'] = 'Keine bekannte Reader-Verbindung gefunden';
    $state['technical_error'] = 'Für diese Nextcloud ist noch keine bekannte Datenbank-Reader-Verbindung gespeichert. Die Nextcloud-Anmeldedaten werden nicht als PostgreSQL-Zugang verwendet.';
    bratonien_tools_nc_wizard_store($state);

    return array('message'=>'Nextcloud wurde gefunden. Für den Datenzugriff wird eine separate Reader-Verbindung benötigt.');
  }

  try
  {
    bratonien_tools_nc_wizard_finish_data_access($state);
  }
  catch (Throwable $e)
  {
    $state['technical_complete'] = false;
    $state['technical_stage'] = 'database_details';
    $state['technical_error'] = $e->getMessage();
  }

  bratonien_tools_nc_wizard_store($state);

  if ($state['technical_stage'] === 'database_details')
  {
    return array('message'=>'Nextcloud wurde gefunden. Die bekannte Reader-Verbindung konnte noch nicht vollständig bestätigt werden.');
  }
  if ($state['technical_stage'] === 'mounts')
  {
    return array('message'=>'Nextcloud und Datenzugriff wurden bestätigt. Ein Speicherort muss noch zugeordnet werden.');
  }

  return array('message'=>'Nextcloud und die gespeicherte Reader-Verbindung wurden erfolgreich bestätigt.');
}

function bratonien_tools_nc_wizard_save_technical_with_known_database()
{
  $state = bratonien_tools_nc_wizard_state();
  if (empty($state['scan_ok'])) throw new RuntimeException('Bitte zuerst Nextcloud erfolgreich scannen.');

  if (trim((string)($state['db_user'] ?? '')) === '' || (string)($state['_db_password'] ?? '') === '')
  {
    bratonien_tools_nc_wizard_apply_known_database_profile($state);
  }

  if (trim((string)($state['db_user'] ?? '')) === '' || (string)($state['_db_password'] ?? '') === '')
  {
    throw new RuntimeException('Für diese Nextcloud sind noch keine Datenbank-Reader-Zugangsdaten bekannt. Nextcloud-Benutzer und -Passwort werden dafür bewusst nicht verwendet.');
  }

  $state['db_host'] = trim((string)($_POST['nc_wizard_db_host'] ?? $state['db_host']));
  $state['db_port'] = (string)max(1, min(65535, (int)($_POST['nc_wizard_db_port'] ?? $state['db_port'])));
  $state['db_database'] = trim((string)($_POST['nc_wizard_db_database'] ?? $state['db_database']));
  $state['technical_stage'] = 'database_details';
  bratonien_tools_nc_wizard_store($state);

  if ($state['db_host'] === '' || $state['db_database'] === '') throw new RuntimeException('Die Adresse der Datenbank ist noch unvollständig.');

  try
  {
    bratonien_tools_nc_wizard_finish_data_access($state);
    bratonien_tools_nc_wizard_store($state);

    return array(
      'message'=>$state['technical_complete']
        ? 'Datenzugriff wurde erfolgreich geprüft.'
        : 'Datenzugriff funktioniert. Ein Speicherort muss noch bestätigt werden.'
    );
  }
  catch (Throwable $e)
  {
    $state['technical_error'] = $e->getMessage();
    $state['technical_stage'] = 'database_details';
    bratonien_tools_nc_wizard_store($state);
    throw $e;
  }
}
