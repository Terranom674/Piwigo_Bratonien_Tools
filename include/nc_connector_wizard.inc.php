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
    'connection_name' => '',
    'scan_message' => '',
    'technical_source' => '',
    'technical_complete' => false,
    'db_host' => '',
    'db_port' => '5432',
    'db_database' => 'nextcloud',
    'db_user' => '',
    'db_password_set' => false,
    'source_view' => 'piwigo_showcase_sources',
    'activity_view' => 'piwigo_showcase_activity',
    'gallery_root' => '',
    'storages' => array(),
    'storage_candidates' => array(),
    'api_status' => 'pending',
    'api_username' => '',
    'api_error' => '',
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

function bratonien_tools_nc_wizard_candidate_urls($host)
{
  $host = trim((string)$host);
  if ($host === '')
  {
    throw new RuntimeException('Bitte die Adresse der Nextcloud angeben.');
  }

  if (preg_match('#^https?://#i', $host))
  {
    return array(bratonien_tools_nc_wizard_normalize_url($host));
  }

  return array(
    bratonien_tools_nc_wizard_normalize_url('https://'.$host),
    bratonien_tools_nc_wizard_normalize_url('http://'.$host),
  );
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
  curl_close($ch);

  if ($body === false || $errno !== 0)
  {
    throw new RuntimeException($error !== '' ? $error : 'Verbindung fehlgeschlagen.');
  }

  return array('status'=>$status, 'body'=>(string)$body);
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

function bratonien_tools_nc_wizard_fill_profile(array &$state)
{
  $url_host = strtolower((string)parse_url($state['base_url'], PHP_URL_HOST));
  $state['db_host'] = $url_host;
  $state['db_port'] = '5432';
  $state['db_database'] = 'nextcloud';
  $state['source_view'] = 'piwigo_showcase_sources';
  $state['activity_view'] = 'piwigo_showcase_activity';
  $state['gallery_root'] = rtrim(PHPWG_ROOT_PATH, '/').'/galleries/nextcloud';
  $state['technical_source'] = 'Standardwerte vorbereitet';

  foreach (bratonien_tools_nc_connector_connections() as $candidate)
  {
    $config = isset($candidate['config']) && is_array($candidate['config']) ? $candidate['config'] : array();
    $candidate_url = trim((string)($config['nextcloud_url'] ?? ''));
    $candidate_host = strtolower(trim((string)($config['host'] ?? '')));
    $match = false;

    if ($candidate_url !== '')
    {
      try
      {
        $match = bratonien_tools_nc_wizard_normalize_url($candidate_url) === $state['base_url'];
      }
      catch (Throwable $ignored)
      {
        $match = false;
      }
    }
    if (!$match && $candidate_host !== '' && $candidate_host === $url_host)
    {
      $match = true;
    }
    if (!$match)
    {
      continue;
    }

    $full = bratonien_tools_nc_connector_connection((int)$candidate['id'], true);
    if (!$full)
    {
      continue;
    }
    $credentials = bratonien_tools_nc_connector_credentials_from_blob($full['secret_blob'] ?? '');

    $state['db_host'] = (string)($config['host'] ?? $state['db_host']);
    $state['db_port'] = (string)($config['port'] ?? $state['db_port']);
    $state['db_database'] = (string)($config['database'] ?? $state['db_database']);
    $state['db_user'] = (string)($config['user'] ?? '');
    $state['_db_password'] = (string)($credentials['db_password'] ?? '');
    $state['db_password_set'] = $state['_db_password'] !== '';
    $state['source_view'] = (string)($config['source_view'] ?? $state['source_view']);
    $state['activity_view'] = (string)($config['activity_view'] ?? $state['activity_view']);
    $state['gallery_root'] = (string)($config['gallery_root'] ?? $state['gallery_root']);
    $state['storages'] = isset($config['storages']) && is_array($config['storages']) ? $config['storages'] : array();
    $state['technical_source'] = 'Passende vorhandene Connector-Konfiguration erkannt';
    break;
  }

  $state['technical_complete'] = $state['db_host'] !== ''
    && $state['db_port'] !== ''
    && $state['db_database'] !== ''
    && $state['db_user'] !== ''
    && !empty($state['db_password_set'])
    && $state['source_view'] !== ''
    && $state['activity_view'] !== ''
    && $state['gallery_root'] !== ''
    && !empty($state['storages']);
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

  $base_url = '';
  $status_data = null;
  foreach (bratonien_tools_nc_wizard_candidate_urls($host_input) as $candidate_url)
  {
    try
    {
      $status_response = bratonien_tools_nc_wizard_http($candidate_url.'/status.php');
      if ($status_response['status'] < 200 || $status_response['status'] >= 300)
      {
        continue;
      }
      $candidate_status = json_decode($status_response['body'], true);
      if (!is_array($candidate_status) || empty($candidate_status['installed']))
      {
        continue;
      }
      $base_url = $candidate_url;
      $status_data = $candidate_status;
      break;
    }
    catch (Throwable $ignored)
    {
      continue;
    }
  }

  if ($base_url === '' || !is_array($status_data))
  {
    throw new RuntimeException('Unter dieser Adresse konnte keine Nextcloud erreicht werden. Der Assistent hat die möglichen Web-Zugänge automatisch geprüft.');
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
    throw new RuntimeException('Nextcloud ist erreichbar, aber die Anmeldung konnte nicht geprüft werden.');
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

  $url_host = (string)parse_url($base_url, PHP_URL_HOST);
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
    'connection_name' => $url_host !== '' ? $url_host : 'Nextcloud',
    'scan_message' => 'Nextcloud wurde erkannt und der Benutzerzugriff wurde bestätigt.',
    '_password' => $password,
    'api_status' => 'pending',
    'api_username' => '',
    'api_error' => '',
  );
  bratonien_tools_nc_wizard_fill_profile($state);
  bratonien_tools_nc_wizard_store($state);

  return array('message'=>'Nextcloud wurde gefunden und der Zugang bestätigt. Fehlende Angaben werden jetzt nur noch bei Bedarf abgefragt.');
}

function bratonien_tools_nc_wizard_save_technical()
{
  $state = bratonien_tools_nc_wizard_state();
  if (empty($state['scan_ok']))
  {
    throw new RuntimeException('Bitte zuerst Nextcloud erfolgreich scannen.');
  }

  $state['connection_name'] = trim((string)($_POST['nc_wizard_connection_name'] ?? $state['connection_name']));
  $state['db_host'] = trim((string)($_POST['nc_wizard_db_host'] ?? $state['db_host']));
  $state['db_port'] = (string)max(1, min(65535, (int)($_POST['nc_wizard_db_port'] ?? $state['db_port'])));
  $state['db_database'] = trim((string)($_POST['nc_wizard_db_database'] ?? $state['db_database']));
  $state['db_user'] = trim((string)($_POST['nc_wizard_db_user'] ?? $state['db_user']));
  $db_password = (string)($_POST['nc_wizard_db_password'] ?? '');
  if ($db_password !== '')
  {
    $state['_db_password'] = $db_password;
    $state['db_password_set'] = true;
  }

  if ($state['connection_name'] === '' || $state['db_host'] === '' || $state['db_database'] === '' || $state['db_user'] === '' || empty($state['db_password_set']))
  {
    bratonien_tools_nc_wizard_store($state);
    throw new RuntimeException('Die noch fehlenden Datenbankangaben müssen vollständig angegeben werden.');
  }

  $config = array(
    'host'=>$state['db_host'],
    'port'=>$state['db_port'],
    'database'=>$state['db_database'],
    'user'=>$state['db_user'],
  );
  $password = (string)($state['_db_password'] ?? '');
  bratonien_tools_nc_connector_psql($config, $password, 'SELECT 1');

  $source_view = bratonien_tools_nc_connector_view_name($state['source_view']);
  $activity_view = bratonien_tools_nc_connector_view_name($state['activity_view']);
  bratonien_tools_nc_connector_psql($config, $password, 'SELECT COUNT(*) FROM '.$source_view);
  bratonien_tools_nc_connector_psql($config, $password, 'SELECT 1 FROM '.$activity_view.' LIMIT 1');

  $rows = bratonien_tools_nc_connector_psql(
    $config,
    $password,
    "SELECT DISTINCT storage_id::text || E'\\t' || source_path FROM ".$source_view." ORDER BY 1"
  );
  $candidates = array();
  foreach (preg_split('/\r\n|\r|\n/', trim($rows)) as $line)
  {
    if ($line === '') continue;
    $parts = explode("\t", $line, 2);
    if (count($parts) !== 2) continue;
    $storage_id = trim($parts[0]);
    $source_path = trim($parts[1], '/');
    $prefix = $source_path;
    if (strpos($source_path, '/') !== false)
    {
      $prefix = substr($source_path, 0, strpos($source_path, '/'));
    }
    if ($storage_id === '') continue;
    if (!isset($candidates[$storage_id]))
    {
      $candidates[$storage_id] = array('storage_id'=>$storage_id, 'source_prefix'=>$prefix, 'local_mount'=>'');
    }
  }

  if (!$candidates)
  {
    throw new RuntimeException('Die Source-View ist erreichbar, liefert aber keine Storage-Zuordnung.');
  }

  $known = array();
  foreach (bratonien_tools_nc_connector_connections() as $connection)
  {
    foreach (($connection['config']['storages'] ?? array()) as $storage)
    {
      $key = (string)($storage['storage_id'] ?? '').'|'.(string)($storage['source_prefix'] ?? '');
      if ($key !== '|') $known[$key] = (string)($storage['local_mount'] ?? '');
    }
  }
  foreach ($candidates as &$candidate)
  {
    $key = $candidate['storage_id'].'|'.$candidate['source_prefix'];
    if (!empty($known[$key]))
    {
      $candidate['local_mount'] = $known[$key];
    }
  }
  unset($candidate);

  $state['storage_candidates'] = array_values($candidates);
  $state['storages'] = array_values(array_filter($state['storage_candidates'], function($storage)
  {
    return trim((string)($storage['local_mount'] ?? '')) !== '';
  }));
  $state['technical_source'] = 'Datenbank und Views erfolgreich geprüft';
  $state['technical_complete'] = count($state['storages']) === count($state['storage_candidates']);
  bratonien_tools_nc_wizard_store($state);

  return array('message'=>$state['technical_complete']
    ? 'Technische Verbindung wurde automatisch vervollständigt.'
    : 'Datenbank und Views wurden erkannt. Für nicht erkannte Storages wird nur noch der lokale Mount abgefragt.');
}

function bratonien_tools_nc_wizard_save_mounts()
{
  $state = bratonien_tools_nc_wizard_state();
  $candidates = isset($state['storage_candidates']) && is_array($state['storage_candidates']) ? $state['storage_candidates'] : array();
  $mounts = isset($_POST['nc_wizard_storage_mount']) && is_array($_POST['nc_wizard_storage_mount']) ? $_POST['nc_wizard_storage_mount'] : array();
  if (!$candidates)
  {
    throw new RuntimeException('Es wurden noch keine Storages erkannt.');
  }

  $storages = array();
  foreach ($candidates as $index => $candidate)
  {
    $mount = trim((string)($candidate['local_mount'] ?? ''));
    if ($mount === '')
    {
      $mount = trim((string)($mounts[$index] ?? ''));
    }
    if ($mount === '' || $mount[0] !== '/')
    {
      throw new RuntimeException('Für Storage '.(string)$candidate['storage_id'].' fehlt ein absoluter lokaler Mount-Pfad.');
    }
    $mount = rtrim($mount, '/');
    if (!is_dir($mount) || !is_readable($mount))
    {
      throw new RuntimeException('Storage-Mount '.$mount.' ist nicht vorhanden oder nicht lesbar.');
    }
    $candidate['local_mount'] = $mount;
    $storages[] = $candidate;
  }

  $state['storages'] = $storages;
  $state['storage_candidates'] = $storages;
  $state['technical_complete'] = true;
  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'Storage-Zuordnung ist vollständig.');
}

function bratonien_tools_nc_wizard_select_user()
{
  $state = bratonien_tools_nc_wizard_state();
  if (empty($state['scan_ok']))
  {
    throw new RuntimeException('Bitte zuerst Nextcloud erfolgreich scannen.');
  }
  if (empty($state['technical_complete']))
  {
    throw new RuntimeException('Die automatische technische Erkennung ist noch nicht vollständig. Bitte nur die noch fehlenden Angaben ergänzen.');
  }

  $showcase_user = trim((string)($_POST['nc_wizard_showcase_user'] ?? ''));
  if ($showcase_user === '')
  {
    throw new RuntimeException('Bitte einen Nextcloud-Benutzer für die Showcase-Freigaben auswählen.');
  }
  $connection_name = trim((string)($_POST['nc_wizard_connection_name'] ?? $state['connection_name']));
  if ($connection_name === '')
  {
    throw new RuntimeException('Bitte einen Namen für die Verbindung angeben.');
  }

  $state['connection_name'] = $connection_name;
  $state['showcase_user'] = $showcase_user;
  $state['step'] = 3;
  $state['api_error'] = '';
  bratonien_tools_nc_wizard_store($state);

  return array('message'=>'Showcase-Benutzer übernommen. Jetzt folgt der Piwigo-API-Zugang.');
}

function bratonien_tools_nc_wizard_api_test()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 3)
  {
    throw new RuntimeException('Der API-Test ist in diesem Assistentenschritt nicht verfügbar.');
  }

  $key_id = trim((string)($_POST['nc_wizard_api_key_id'] ?? ''));
  $secret = trim((string)($_POST['nc_wizard_api_key_secret'] ?? ''));
  if ($key_id === '' && $secret === '')
  {
    $stored = bratonien_tools_nc_api_credentials();
    $key_id = trim((string)($stored['key_id'] ?? ''));
    $secret = trim((string)($stored['key_secret'] ?? ''));
  }
  if ($key_id === '' || $secret === '')
  {
    throw new RuntimeException('API-Schlüssel-ID und API-Geheimnis fehlen.');
  }

  try
  {
    $status = bratonien_tools_nc_connector_piwigo_api_request($key_id, $secret, 'pwg.session.getStatus');
    $user_status = strtolower((string)($status['status'] ?? ''));
    if (!in_array($user_status, array('admin','webmaster'), true))
    {
      throw new RuntimeException('Der API-Key gehört keinem Piwigo-Administrator/Webmaster.');
    }

    $method_result = bratonien_tools_nc_connector_piwigo_api_request($key_id, $secret, 'reflection.getMethodList');
    $method_map = array();
    bratonien_tools_nc_connector_collect_method_names($method_result, $method_map);
    $missing = array_values(array_diff(array('bratonien.nc.syncProductive','bratonien.nc.syncOrphans'), array_keys($method_map)));
    if ($missing)
    {
      throw new RuntimeException('Benötigte Bratonien-Sync-Methoden fehlen: '.implode(', ', $missing).'.');
    }

    bratonien_tools_nc_api_credentials_store($key_id, $secret);
    $state['api_status'] = 'ok';
    $state['api_username'] = (string)($status['username'] ?? $status['user'] ?? '');
    $state['api_error'] = '';
    $state['step'] = 4;
    bratonien_tools_nc_wizard_store($state);
    return array('message'=>'Piwigo-API erfolgreich geprüft. Zum Abschluss kann jetzt der Fallback festgelegt werden.');
  }
  catch (Throwable $e)
  {
    $state['api_status'] = 'error';
    $state['api_error'] = $e->getMessage();
    bratonien_tools_nc_wizard_store($state);
    throw $e;
  }
}

function bratonien_tools_nc_wizard_api_skip()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 3)
  {
    throw new RuntimeException('Die API kann in diesem Assistentenschritt nicht übersprungen werden.');
  }
  $state['api_status'] = 'skipped';
  $state['api_error'] = '';
  $state['step'] = 4;
  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'Piwigo-API wurde übersprungen. Für diese Verbindung ist deshalb ein Fallback-Zugang erforderlich.');
}

function bratonien_tools_nc_wizard_finish()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 4 || empty($state['technical_complete']) || trim((string)$state['showcase_user']) === '')
  {
    throw new RuntimeException('Der Assistent ist noch nicht vollständig.');
  }

  $fallback_user = trim((string)($_POST['nc_wizard_fallback_user'] ?? ''));
  $fallback_password = (string)($_POST['nc_wizard_fallback_password'] ?? '');
  if (($fallback_user === '') !== ($fallback_password === ''))
  {
    throw new RuntimeException('Fallback-Benutzer und Fallback-Passwort müssen entweder beide angegeben oder beide leer gelassen werden.');
  }
  if ($state['api_status'] !== 'ok' && $fallback_user === '')
  {
    throw new RuntimeException('Da die Piwigo-API übersprungen wurde, ist ein Fallback-Zugang erforderlich.');
  }

  $storage_lines = array();
  foreach ($state['storages'] as $storage)
  {
    $storage_lines[] = (string)$storage['storage_id'].' | '.(string)$storage['source_prefix'].' | '.(string)$storage['local_mount'];
  }

  $_POST['nc_name'] = (string)$state['connection_name'];
  $_POST['nc_host'] = (string)$state['db_host'];
  $_POST['nc_port'] = (string)$state['db_port'];
  $_POST['nc_database'] = (string)$state['db_database'];
  $_POST['nc_user'] = (string)$state['db_user'];
  $_POST['nc_db_password'] = (string)($state['_db_password'] ?? '');
  $_POST['nc_source_view'] = (string)$state['source_view'];
  $_POST['nc_activity_view'] = (string)$state['activity_view'];
  $_POST['nc_gallery_root'] = (string)$state['gallery_root'];
  $_POST['nc_storages'] = implode("\n", $storage_lines);
  $_POST['nc_quiet_seconds'] = '120';
  $_POST['nc_max_wait_seconds'] = '900';
  $_POST['nc_full_sync_seconds'] = '86400';
  $_POST['nc_piwigo_user'] = $fallback_user;
  $_POST['nc_piwigo_password'] = $fallback_password;
  $_POST['nc_nextcloud_url'] = (string)$state['base_url'];
  $_POST['nc_showcase_user'] = (string)$state['showcase_user'];
  $_POST['nc_access_user'] = (string)$state['username'];
  $_POST['nc_product'] = (string)$state['product'];
  $_POST['nc_version'] = (string)$state['version'];

  $result = bratonien_tools_nc_connector_create_local_api_first();
  unset($_SESSION['bratonien_nc_wizard']);
  $result['message'] = 'Verbindung wurde vollständig durch den Assistenten angelegt. '.$result['message'];
  return $result;
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
