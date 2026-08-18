<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_wizard_state()
{
  $state = isset($_SESSION['bratonien_nc_wizard']) && is_array($_SESSION['bratonien_nc_wizard'])
    ? $_SESSION['bratonien_nc_wizard']
    : array();

  return array_merge(array(
    'step' => 1,
    'scan_ok' => false,
    'base_url' => '',
    'host_input' => '',
    'username' => '',
    'display_name' => '',
    'version' => '',
    'product' => 'Nextcloud',
    'users' => array(),
    'can_list_users' => false,
    'showcase_user' => '',
    'scan_message' => '',
  ), $state);
}

function bratonien_tools_nc_wizard_store(array $state)
{
  $_SESSION['bratonien_nc_wizard'] = $state;
}

function bratonien_tools_nc_wizard_reset()
{
  unset($_SESSION['bratonien_nc_wizard']);
  return array('message'=>'Verbindungsassistent wurde zurückgesetzt.');
}

function bratonien_tools_nc_wizard_normalize_url($host)
{
  $host = trim((string)$host);
  if ($host === '')
  {
    throw new RuntimeException('Nextcloud-Host fehlt.');
  }

  if (!preg_match('#^https?://#i', $host))
  {
    $host = 'https://'.$host;
  }

  $parts = parse_url($host);
  if (!is_array($parts) || empty($parts['host']))
  {
    throw new RuntimeException('Nextcloud-Host ist ungültig.');
  }

  $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
  if (!in_array($scheme, array('http','https'), true))
  {
    throw new RuntimeException('Nextcloud muss per HTTP oder HTTPS erreichbar sein.');
  }

  $url = $scheme.'://'.$parts['host'];
  if (!empty($parts['port']))
  {
    $url .= ':'.(int)$parts['port'];
  }
  if (!empty($parts['path']) && $parts['path'] !== '/')
  {
    $url .= '/'.trim($parts['path'], '/');
  }

  return rtrim($url, '/');
}

function bratonien_tools_nc_wizard_http($url, $username = '', $password = '', array $headers = array())
{
  if (!function_exists('curl_init'))
  {
    throw new RuntimeException('cURL ist in PHP nicht verfügbar. Der Nextcloud-Scan kann nicht ausgeführt werden.');
  }

  $ch = curl_init($url);
  $http_headers = array_merge(array('Accept: application/json'), $headers);
  $options = array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => $http_headers,
    CURLOPT_USERAGENT => 'Bratonien-Tools-NC-Wizard/'.(function_exists('bratonien_tools_current_version') ? bratonien_tools_current_version() : 'dev'),
  );
  if ($username !== '')
  {
    $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    $options[CURLOPT_USERPWD] = $username.':'.$password;
  }
  curl_setopt_array($ch, $options);

  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $effective_url = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  curl_close($ch);

  if ($body === false || $errno !== 0)
  {
    throw new RuntimeException('Nextcloud konnte nicht erreicht werden: '.$error);
  }

  return array('status'=>$status, 'body'=>(string)$body, 'url'=>$effective_url);
}

function bratonien_tools_nc_wizard_ocs_data($body)
{
  $decoded = json_decode((string)$body, true);
  if (!is_array($decoded))
  {
    throw new RuntimeException('Nextcloud hat keine gültige JSON-Antwort geliefert.');
  }

  if (isset($decoded['ocs']['meta']['statuscode']) && (int)$decoded['ocs']['meta']['statuscode'] !== 100)
  {
    $message = (string)($decoded['ocs']['meta']['message'] ?? 'Nextcloud hat die Anfrage abgelehnt.');
    throw new RuntimeException($message);
  }

  return isset($decoded['ocs']['data']) && is_array($decoded['ocs']['data']) ? $decoded['ocs']['data'] : array();
}

function bratonien_tools_nc_wizard_scan()
{
  $host_input = trim((string)($_POST['nc_wizard_host'] ?? ''));
  $username = trim((string)($_POST['nc_wizard_user'] ?? ''));
  $password = (string)($_POST['nc_wizard_password'] ?? '');
  if ($username === '' || $password === '')
  {
    throw new RuntimeException('Nextcloud-Benutzer und Passwort werden für den Scan benötigt.');
  }

  $base_url = bratonien_tools_nc_wizard_normalize_url($host_input);

  $status_response = bratonien_tools_nc_wizard_http($base_url.'/status.php');
  if ($status_response['status'] < 200 || $status_response['status'] >= 300)
  {
    throw new RuntimeException('Unter diesem Host wurde keine erreichbare Nextcloud-Instanz erkannt (HTTP '.$status_response['status'].').');
  }
  $status_data = json_decode($status_response['body'], true);
  if (!is_array($status_data) || empty($status_data['installed']))
  {
    throw new RuntimeException('Der Host antwortet, wurde aber nicht als installierte Nextcloud-Instanz erkannt.');
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
    throw new RuntimeException('Nextcloud-Benutzerprüfung ist fehlgeschlagen (HTTP '.$user_response['status'].').');
  }
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
    $users = array();
    $can_list_users = false;
  }

  $state = array(
    'step' => 2,
    'scan_ok' => true,
    'base_url' => $base_url,
    'host_input' => $host_input,
    'username' => (string)($user_data['id'] ?? $username),
    'display_name' => (string)($user_data['display-name'] ?? $user_data['displayname'] ?? ''),
    'version' => (string)($status_data['versionstring'] ?? $status_data['version'] ?? ''),
    'product' => (string)($status_data['productname'] ?? 'Nextcloud'),
    'users' => $users,
    'can_list_users' => $can_list_users,
    'showcase_user' => '',
    'scan_message' => 'Nextcloud wurde erkannt und der Benutzerzugriff wurde bestätigt.',
    '_password' => $password,
  );
  bratonien_tools_nc_wizard_store($state);

  return array('message'=>'Nextcloud-Scan erfolgreich. Die erkannten Angaben können jetzt geprüft werden.');
}

function bratonien_tools_nc_wizard_select_user()
{
  $state = bratonien_tools_nc_wizard_state();
  if (empty($state['scan_ok']))
  {
    throw new RuntimeException('Bitte zuerst Nextcloud erfolgreich scannen.');
  }

  $showcase_user = trim((string)($_POST['nc_wizard_showcase_user'] ?? ''));
  if ($showcase_user === '')
  {
    throw new RuntimeException('Bitte einen Nextcloud-Benutzer für die Showcase-Freigaben auswählen.');
  }

  $state['showcase_user'] = $showcase_user;
  $state['step'] = 3;
  bratonien_tools_nc_wizard_store($state);

  return array('message'=>'Showcase-Benutzer übernommen. Als Nächstes wird der Piwigo-API-Zugang geprüft.');
}

function bratonien_tools_nc_connector_update_name()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $name = trim((string)($_POST['connection_name'] ?? ''));
  if ($id < 1 || $name === '')
  {
    throw new RuntimeException('Verbindung oder Name fehlt.');
  }

  $connection = bratonien_tools_nc_connector_connection($id, false);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }

  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET name='".pwg_db_real_escape_string($name)."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".$id." LIMIT 1");

  return array('message'=>'Verbindungsname wurde aktualisiert.');
}

function bratonien_tools_nc_connector_update_technical()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }
  if (!empty($connection['enabled']) || (string)$connection['takeover_state'] === 'active')
  {
    throw new RuntimeException('Technische Einstellungen einer aktiven Verbindung können nicht geändert werden. Bitte zuerst deaktivieren.');
  }

  $config = $connection['config'];
  $port = (int)($_POST['nc_port'] ?? ($config['port'] ?? 5432));
  if ($port < 1 || $port > 65535)
  {
    throw new RuntimeException('Ungültiger PostgreSQL-Port.');
  }

  $gallery_root = rtrim(trim((string)($_POST['nc_gallery_root'] ?? ($config['gallery_root'] ?? ''))), '/');
  if ($gallery_root === '' || $gallery_root[0] !== '/')
  {
    throw new RuntimeException('Der Galerie-Pfad muss ein absoluter Pfad sein.');
  }

  $config['host'] = trim((string)($_POST['nc_host'] ?? ($config['host'] ?? '')));
  $config['port'] = (string)$port;
  $config['database'] = trim((string)($_POST['nc_database'] ?? ($config['database'] ?? '')));
  $config['user'] = trim((string)($_POST['nc_user'] ?? ($config['user'] ?? '')));
  $config['source_view'] = trim((string)($_POST['nc_source_view'] ?? ($config['source_view'] ?? '')));
  $config['activity_view'] = trim((string)($_POST['nc_activity_view'] ?? ($config['activity_view'] ?? '')));
  $config['gallery_root'] = $gallery_root;
  $config['quiet_seconds'] = max(0, (int)($_POST['nc_quiet_seconds'] ?? ($config['quiet_seconds'] ?? 120)));
  $config['max_wait_seconds'] = max(60, (int)($_POST['nc_max_wait_seconds'] ?? ($config['max_wait_seconds'] ?? 900)));
  $config['full_sync_seconds'] = max(300, (int)($_POST['nc_full_sync_seconds'] ?? ($config['full_sync_seconds'] ?? 86400)));
  $config['storages'] = bratonien_tools_nc_connector_parse_storages($_POST['nc_storages'] ?? '');
  unset($config['verification']);

  bratonien_tools_nc_connector_view_name($config['source_view']);
  bratonien_tools_nc_connector_view_name($config['activity_view']);

  $credentials = bratonien_tools_nc_connector_credentials_from_blob($connection['secret_blob'] ?? '');
  $db_password = (string)($_POST['nc_db_password'] ?? '');
  if ($db_password === '')
  {
    $db_password = $credentials['db_password'];
  }

  $secret_blob = bratonien_tools_nc_connector_encrypt_credentials(
    $db_password,
    $credentials['piwigo_user'],
    $credentials['piwigo_password']
  );

  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json))
  {
    throw new RuntimeException('Connector-Konfiguration konnte nicht serialisiert werden.');
  }

  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET takeover_state='disabled', enabled=0, config_json='".pwg_db_real_escape_string($config_json)."', secret_blob='".pwg_db_real_escape_string($secret_blob)."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".$id." LIMIT 1");

  return array('message'=>'Technische Verbindungseinstellungen wurden gespeichert. Die Verbindung muss erneut geprüft werden.');
}
