<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_wizard_scan_user_scoped()
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
    $state['technical_error'] = 'Für diese Nextcloud ist noch keine bekannte Datenbank-Reader-Verbindung gespeichert.';
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
  return array('message'=>'Nextcloud und Datenzugriff wurden bestätigt. Jetzt können die Verzeichnisse des angemeldeten Benutzers gewählt werden.');
}

function bratonien_tools_nc_wizard_select_current_user()
{
  $state = bratonien_tools_nc_wizard_state();
  if (empty($state['scan_ok'])) throw new RuntimeException('Bitte zuerst Nextcloud erfolgreich scannen.');
  if (empty($state['technical_complete'])) throw new RuntimeException('Die Verbindung ist noch nicht vollständig geprüft.');

  $connection_name = trim((string)($_POST['nc_wizard_connection_name'] ?? $state['connection_name']));
  if ($connection_name === '') throw new RuntimeException('Bitte einen Namen für die Verbindung angeben.');

  $state['connection_name'] = $connection_name;
  $state['showcase_user'] = (string)$state['username'];
  $state['step'] = 3;
  $state['api_error'] = '';
  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'Nextcloud-Benutzer übernommen. Jetzt folgt der Piwigo-API-Zugang.');
}

function bratonien_tools_nc_wizard_webdav_list(array $state, $path = '')
{
  if (empty($state['scan_ok']) || trim((string)$state['base_url']) === '' || trim((string)$state['username']) === '' || (string)$state['_password'] === '')
  {
    throw new RuntimeException('Die Nextcloud-Sitzung des Assistenten ist nicht vollständig.');
  }
  if (!function_exists('curl_init')) throw new RuntimeException('cURL ist für die Verzeichnisauswahl nicht verfügbar.');

  $path = trim((string)$path, '/');
  if ($path !== '' && preg_match('#(^|/)\.\.(/|$)#', $path)) throw new RuntimeException('Ungültiger Verzeichnispfad.');

  $segments = $path === '' ? array() : array_map('rawurlencode', explode('/', $path));
  $user = rawurlencode((string)$state['username']);
  $url = rtrim((string)$state['base_url'], '/').'/remote.php/dav/files/'.$user.'/'.implode('/', $segments);
  if (substr($url, -1) !== '/') $url .= '/';

  $body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:displayname/></d:prop></d:propfind>';
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CUSTOMREQUEST=>'PROPFIND',
    CURLOPT_POSTFIELDS=>$body,
    CURLOPT_HTTPHEADER=>array('Depth: 1','Content-Type: application/xml; charset=utf-8'),
    CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
    CURLOPT_USERPWD=>(string)$state['username'].':'.(string)$state['_password'],
    CURLOPT_CONNECTTIMEOUT=>8,
    CURLOPT_TIMEOUT=>20,
  ));
  $response = curl_exec($ch);
  $errno = curl_errno($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($response === false || $errno !== 0) throw new RuntimeException('Nextcloud-Verzeichnisse konnten nicht geladen werden.');
  if ($status === 401 || $status === 403) throw new RuntimeException('Nextcloud hat den Zugriff auf dieses Verzeichnis abgelehnt.');
  if ($status !== 207) throw new RuntimeException('Nextcloud-Verzeichnisabfrage antwortete mit HTTP '.$status.'.');

  libxml_use_internal_errors(true);
  $xml = simplexml_load_string((string)$response);
  if ($xml === false) throw new RuntimeException('Nextcloud hat eine ungültige WebDAV-Antwort geliefert.');
  $xml->registerXPathNamespace('d', 'DAV:');
  $children = array();
  foreach ($xml->xpath('//d:response') as $item)
  {
    $item->registerXPathNamespace('d', 'DAV:');
    $hrefs = $item->xpath('d:href');
    $collections = $item->xpath('d:propstat/d:prop/d:resourcetype/d:collection');
    if (!$hrefs || !$collections) continue;
    $href = rawurldecode((string)$hrefs[0]);
    $href_path = (string)parse_url($href, PHP_URL_PATH);
    $base_path = (string)parse_url($url, PHP_URL_PATH);
    if (rtrim($href_path, '/') === rtrim($base_path, '/')) continue;
    $name = basename(rtrim($href_path, '/'));
    if ($name === '') continue;
    $child_path = $path === '' ? $name : $path.'/'.$name;
    $children[$child_path] = $name;
  }
  natcasesort($children);

  $parent = '';
  if ($path !== '')
  {
    $parts = explode('/', $path);
    array_pop($parts);
    $parent = implode('/', $parts);
  }
  return array('current'=>$path, 'parent'=>$parent, 'children'=>$children);
}

function bratonien_tools_nc_wizard_back()
{
  $state = bratonien_tools_nc_wizard_state();
  $step = (int)($state['step'] ?? 1);
  if ($step <= 1) return array('message'=>'Bereits am Anfang des Assistenten.');
  if ($step === 2)
  {
    $state['step'] = 1;
  }
  elseif ($step === 3)
  {
    $state['step'] = 2;
    $state['technical_complete'] = true;
    $state['technical_stage'] = 'ready';
  }
  else
  {
    $state['step'] = 3;
  }
  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'Ein Schritt zurück.');
}
