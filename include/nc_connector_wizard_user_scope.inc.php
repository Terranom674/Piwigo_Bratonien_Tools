<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
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

  $body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><d:resourcetype/><d:displayname/><oc:fileid/></d:prop></d:propfind>';
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
  $xml->registerXPathNamespace('oc', 'http://owncloud.org/ns');

  $children = array();
  $fileids = array();
  $current_fileid = 0;
  $base_path = (string)parse_url($url, PHP_URL_PATH);

  foreach ($xml->xpath('//d:response') as $item)
  {
    $item->registerXPathNamespace('d', 'DAV:');
    $item->registerXPathNamespace('oc', 'http://owncloud.org/ns');
    $hrefs = $item->xpath('d:href');
    $collections = $item->xpath('d:propstat/d:prop/d:resourcetype/d:collection');
    $ids = $item->xpath('d:propstat/d:prop/oc:fileid');
    if (!$hrefs || !$collections || !$ids) continue;

    $fileid = (int)trim((string)$ids[0]);
    if ($fileid < 1) continue;
    $href = rawurldecode((string)$hrefs[0]);
    $href_path = (string)parse_url($href, PHP_URL_PATH);

    if (rtrim($href_path, '/') === rtrim($base_path, '/'))
    {
      $current_fileid = $fileid;
      continue;
    }

    $name = basename(rtrim($href_path, '/'));
    if ($name === '') continue;
    $child_path = $path === '' ? $name : $path.'/'.$name;
    $children[$child_path] = $name;
    $fileids[$child_path] = $fileid;
  }
  natcasesort($children);

  if ($current_fileid < 1) throw new RuntimeException('Nextcloud hat für das aktuelle Verzeichnis keine eindeutige Datei-ID geliefert.');

  $parent = '';
  if ($path !== '')
  {
    $parts = explode('/', $path);
    array_pop($parts);
    $parent = implode('/', $parts);
  }

  return array(
    'current'=>$path,
    'parent'=>$parent,
    'children'=>$children,
    'current_fileid'=>$current_fileid,
    'fileids'=>$fileids,
  );
}

function bratonien_tools_nc_wizard_refresh_directory_state(array &$state, $path = null)
{
  if ($path === null) $path = (string)($state['directory_path'] ?? '');
  $listing = bratonien_tools_nc_wizard_webdav_list($state, $path);
  $state['directory_path'] = (string)$listing['current'];
  $state['directory_parent'] = (string)$listing['parent'];
  $state['directory_children'] = (array)$listing['children'];
  $state['directory_current_fileid'] = (int)$listing['current_fileid'];
  $state['directory_fileids'] = (array)$listing['fileids'];
  if (!isset($state['directory_selected']) || !is_array($state['directory_selected'])) $state['directory_selected'] = array();
  if (!isset($state['directory_selected_fileids']) || !is_array($state['directory_selected_fileids'])) $state['directory_selected_fileids'] = array();
}

function bratonien_tools_nc_wizard_generic_db_config(array $state)
{
  return array(
    'host'=>(string)$state['db_host'],
    'port'=>(string)$state['db_port'],
    'database'=>(string)$state['db_database'],
    'user'=>(string)$state['db_user'],
  );
}

function bratonien_tools_nc_wizard_verify_generic_data_access(array &$state)
{
  $config = bratonien_tools_nc_wizard_generic_db_config($state);
  $password = (string)$state['_db_password'];
  if (trim((string)$config['user']) === '' || $password === '') throw new RuntimeException('Die gespeicherte Reader-Verbindung ist unvollständig.');

  bratonien_tools_nc_connector_psql($config, $password, 'SELECT 1');
  bratonien_tools_nc_connector_psql($config, $password, 'SELECT 1 FROM piwigo_connector_files LIMIT 1');
  bratonien_tools_nc_connector_psql($config, $password, 'SELECT 1 FROM piwigo_connector_activity LIMIT 1');

  $state['source_view'] = 'piwigo_connector_files';
  $state['activity_view'] = 'piwigo_connector_activity';
  $state['source_mode'] = 'selected-fileids';
  $state['storages'] = array();
  $state['storage_candidates'] = array();
  $state['roots'] = array();
  $state['technical_complete'] = false;
  $state['technical_stage'] = 'mounts';
  $state['technical_source'] = 'Generische Reader-Schnittstelle geprüft';
  $state['technical_error'] = '';
  $state['directory_selection_ready'] = true;
}

function bratonien_tools_nc_wizard_known_adapter($storage_id, $source_path)
{
  $storage_id = trim((string)$storage_id);
  $source_path = trim((string)$source_path, '/');
  $matches = array();

  foreach (bratonien_tools_nc_connector_connections() as $connection)
  {
    foreach ((array)($connection['config']['storages'] ?? array()) as $storage)
    {
      if ((string)($storage['storage_id'] ?? '') !== $storage_id) continue;
      $prefix = trim((string)($storage['source_prefix'] ?? ''), '/');
      if ($prefix !== '' && $source_path !== $prefix && strpos($source_path, $prefix.'/') !== 0) continue;
      $mount = rtrim(trim((string)($storage['local_mount'] ?? '')), '/');
      if ($mount === '' || !is_dir($mount) || !is_readable($mount)) continue;
      $key = $prefix.'|'.$mount;
      $matches[$key] = array('storage_id'=>$storage_id,'source_prefix'=>$prefix,'local_mount'=>$mount);
    }
  }

  if (!$matches) return null;
  $max = -1;
  foreach ($matches as $match) $max = max($max, strlen((string)$match['source_prefix']));
  $best = array_values(array_filter($matches, function($match) use ($max) { return strlen((string)$match['source_prefix']) === $max; }));
  return count($best) === 1 ? $best[0] : null;
}

function bratonien_tools_nc_wizard_resolve_selected_roots(array &$state)
{
  $selected = isset($state['directory_selected']) && is_array($state['directory_selected']) ? $state['directory_selected'] : array();
  $selected_fileids = isset($state['directory_selected_fileids']) && is_array($state['directory_selected_fileids']) ? $state['directory_selected_fileids'] : array();
  if (!$selected) throw new RuntimeException('Bitte mindestens ein Nextcloud-Verzeichnis auswählen.');

  $ids = array();
  foreach ($selected as $path)
  {
    if (!isset($selected_fileids[$path]) || (int)$selected_fileids[$path] < 1) throw new RuntimeException('Für eine Auswahl fehlt die eindeutige Nextcloud-Datei-ID. Bitte das Verzeichnis erneut auswählen.');
    $ids[(int)$selected_fileids[$path]] = (string)$path;
  }

  $config = bratonien_tools_nc_wizard_generic_db_config($state);
  $id_list = implode(',', array_map('intval', array_keys($ids)));
  $rows = bratonien_tools_nc_connector_psql(
    $config,
    (string)$state['_db_password'],
    "SELECT fileid::text || E'\\t' || storage_id || E'\\t' || COALESCE(source_path, '') FROM piwigo_connector_files WHERE fileid IN (".$id_list.") ORDER BY fileid"
  );

  $resolved = array();
  foreach (preg_split('/\r\n|\r|\n/', trim($rows)) as $line)
  {
    if ($line === '') continue;
    $parts = explode("\t", $line, 3);
    if (count($parts) !== 3 || !ctype_digit($parts[0])) continue;
    $resolved[(int)$parts[0]] = array('storage_id'=>(string)$parts[1], 'source_path'=>trim((string)$parts[2], '/'));
  }

  $roots = array();
  $candidates = array();
  foreach ($ids as $fileid=>$path)
  {
    if (!isset($resolved[$fileid])) throw new RuntimeException('Das ausgewählte Nextcloud-Verzeichnis mit Datei-ID '.$fileid.' konnte nicht mehr aufgelöst werden.');
    $storage_id = trim((string)$resolved[$fileid]['storage_id']);
    $source_path = trim((string)$resolved[$fileid]['source_path'], '/');
    if ($storage_id === '') throw new RuntimeException('Nextcloud hat für Datei-ID '.$fileid.' keine Storage-ID geliefert.');

    $display_name = $path === '' ? ((string)($state['display_name'] ?? '') !== '' ? (string)$state['display_name'] : 'Nextcloud') : basename($path);
    $roots[] = array(
      'fileid'=>(int)$fileid,
      'display_name'=>$display_name,
      'webdav_path'=>$path,
      'storage_id'=>$storage_id,
      'source_path'=>$source_path,
    );

    $adapter = bratonien_tools_nc_wizard_known_adapter($storage_id, $source_path);
    if (!$adapter) $adapter = array('storage_id'=>$storage_id,'source_prefix'=>'','local_mount'=>'');
    $key = $adapter['storage_id'].'|'.$adapter['source_prefix'].'|'.$adapter['local_mount'];
    $candidates[$key] = $adapter;
  }

  $state['roots'] = array_values($roots);
  $state['storage_candidates'] = array_values($candidates);
  $state['storages'] = $state['storage_candidates'];

  $all_mapped = !empty($state['storage_candidates']);
  foreach ($state['storage_candidates'] as $candidate)
  {
    $mount = rtrim(trim((string)($candidate['local_mount'] ?? '')), '/');
    if ($mount === '' || !is_dir($mount) || !is_readable($mount)) $all_mapped = false;
  }

  if ($all_mapped)
  {
    $state['technical_complete'] = true;
    $state['technical_stage'] = 'ready';
    $state['directory_selection_ready'] = false;
    return;
  }

  $state['technical_complete'] = false;
  $state['technical_stage'] = 'mounts';
  $state['directory_selection_ready'] = false;
  $state['mount_prompted'] = true;
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
    catch (Throwable $ignored) {}
  }
  if ($base_url === '' || !is_array($status_data)) throw new RuntimeException('Unter dieser Adresse konnte keine Nextcloud erreicht werden. HTTP und HTTPS wurden automatisch geprüft.');

  $user_response = bratonien_tools_nc_wizard_http($base_url.'/ocs/v2.php/cloud/user?format=json', $username, $password, array('OCS-APIRequest: true'));
  if ($user_response['status'] === 401 || $user_response['status'] === 403) throw new RuntimeException('Nextcloud hat Benutzername oder Passwort abgelehnt.');
  if ($user_response['status'] < 200 || $user_response['status'] >= 300) throw new RuntimeException('Nextcloud ist erreichbar, aber die Anmeldung konnte nicht geprüft werden.');

  $user_data = bratonien_tools_nc_wizard_ocs_data($user_response['body']);
  $resolved_username = trim((string)($user_data['id'] ?? $username));
  if ($resolved_username === '') $resolved_username = $username;
  $url_host = (string)parse_url($base_url, PHP_URL_HOST);

  $state = array_merge($state, array(
    'step'=>2,'scan_ok'=>true,'base_url'=>$base_url,'host_input'=>$host_input,'username'=>$resolved_username,
    'display_name'=>(string)($user_data['display-name'] ?? $user_data['displayname'] ?? ''),
    'version'=>(string)($status_data['versionstring'] ?? $status_data['version'] ?? ''),'product'=>(string)($status_data['productname'] ?? 'Nextcloud'),
    'users'=>array(),'can_list_users'=>false,'showcase_user'=>$resolved_username,'connection_name'=>$url_host !== '' ? $url_host : 'Nextcloud',
    'scan_message'=>'Nextcloud wurde erkannt und der Benutzerzugriff wurde bestätigt.','_password'=>$password,
    'api_status'=>'pending','api_username'=>'','api_error'=>'','db_host'=>strtolower($url_host),'db_port'=>'5432','db_database'=>'nextcloud','db_user'=>'','db_password_set'=>false,'_db_password'=>'',
    'source_view'=>'piwigo_connector_files','activity_view'=>'piwigo_connector_activity','source_mode'=>'selected-fileids','gallery_root'=>rtrim(PHPWG_ROOT_PATH, '/').'/galleries/nextcloud',
    'storages'=>array(),'storage_candidates'=>array(),'roots'=>array(),'technical_stage'=>'auto_check','technical_source'=>'Automatische Prüfung','technical_error'=>'','technical_complete'=>false,
    'directory_selection_ready'=>false,'directory_path'=>'','directory_parent'=>'','directory_children'=>array(),'directory_current_fileid'=>0,'directory_fileids'=>array(),'directory_selected'=>array(),'directory_selected_fileids'=>array(),
    'database_prompted'=>false,'mount_prompted'=>false,
  ));

  if (!bratonien_tools_nc_wizard_apply_known_database_profile($state))
  {
    $state['technical_stage'] = 'database_details';
    $state['database_prompted'] = true;
    $state['technical_source'] = 'Keine bekannte Reader-Verbindung gefunden';
    $state['technical_error'] = 'Für diese Nextcloud ist noch keine bekannte Datenbank-Reader-Verbindung gespeichert.';
    bratonien_tools_nc_wizard_store($state);
    return array('message'=>'Nextcloud wurde gefunden. Für den Datenzugriff wird eine separate Reader-Verbindung benötigt.');
  }

  try
  {
    bratonien_tools_nc_wizard_verify_generic_data_access($state);
    bratonien_tools_nc_wizard_refresh_directory_state($state, '');
  }
  catch (Throwable $e)
  {
    $state['technical_complete'] = false;
    $state['technical_stage'] = 'database_details';
    $state['database_prompted'] = true;
    $state['technical_error'] = $e->getMessage().' Die generischen Connector-Views müssen in Nextcloud eingerichtet sein.';
  }
  bratonien_tools_nc_wizard_store($state);

  if ($state['technical_stage'] === 'database_details') return array('message'=>'Nextcloud wurde gefunden. Die generische Reader-Schnittstelle konnte noch nicht bestätigt werden.');
  return array('message'=>'Nextcloud und generischer Datenzugriff wurden bestätigt. Jetzt können die Verzeichnisse des angemeldeten Benutzers gewählt werden.');
}

function bratonien_tools_nc_wizard_save_technical_generic()
{
  $state = bratonien_tools_nc_wizard_state();
  if (empty($state['scan_ok'])) throw new RuntimeException('Bitte zuerst Nextcloud erfolgreich scannen.');
  if (trim((string)($state['db_user'] ?? '')) === '' || (string)($state['_db_password'] ?? '') === '') bratonien_tools_nc_wizard_apply_known_database_profile($state);
  if (trim((string)($state['db_user'] ?? '')) === '' || (string)($state['_db_password'] ?? '') === '') throw new RuntimeException('Für diese Nextcloud sind noch keine Datenbank-Reader-Zugangsdaten bekannt.');

  $state['db_host'] = trim((string)($_POST['nc_wizard_db_host'] ?? $state['db_host']));
  $state['db_port'] = (string)max(1, min(65535, (int)($_POST['nc_wizard_db_port'] ?? $state['db_port'])));
  $state['db_database'] = trim((string)($_POST['nc_wizard_db_database'] ?? $state['db_database']));
  if ($state['db_host'] === '' || $state['db_database'] === '') throw new RuntimeException('Die Adresse der Datenbank ist noch unvollständig.');

  try
  {
    bratonien_tools_nc_wizard_verify_generic_data_access($state);
    bratonien_tools_nc_wizard_refresh_directory_state($state, '');
    bratonien_tools_nc_wizard_store($state);
    return array('message'=>'Generischer Datenzugriff wurde erfolgreich geprüft. Jetzt können Verzeichnisse ausgewählt werden.');
  }
  catch (Throwable $e)
  {
    $state['technical_error'] = $e->getMessage();
    $state['technical_stage'] = 'database_details';
    $state['database_prompted'] = true;
    bratonien_tools_nc_wizard_store($state);
    throw $e;
  }
}

function bratonien_tools_nc_wizard_directory_browse()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 2 || (string)$state['technical_stage'] !== 'mounts' || empty($state['directory_selection_ready'])) throw new RuntimeException('Die Verzeichnisauswahl ist in diesem Fenster nicht verfügbar.');
  $path = trim((string)($_POST['nc_wizard_directory_path'] ?? ''), '/');
  bratonien_tools_nc_wizard_refresh_directory_state($state, $path);
  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'Verzeichnis geöffnet.');
}

function bratonien_tools_nc_wizard_directory_add()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 2 || (string)$state['technical_stage'] !== 'mounts' || empty($state['directory_selection_ready'])) throw new RuntimeException('Die Verzeichnisauswahl ist in diesem Fenster nicht verfügbar.');
  $path = trim((string)($state['directory_path'] ?? ''), '/');
  $fileid = (int)($state['directory_current_fileid'] ?? 0);
  if ($fileid < 1) throw new RuntimeException('Für dieses Verzeichnis fehlt die eindeutige Nextcloud-Datei-ID.');
  $selected = isset($state['directory_selected']) && is_array($state['directory_selected']) ? $state['directory_selected'] : array();
  if (!in_array($path, $selected, true)) $selected[] = $path;
  $state['directory_selected'] = array_values($selected);
  if (!isset($state['directory_selected_fileids']) || !is_array($state['directory_selected_fileids'])) $state['directory_selected_fileids'] = array();
  $state['directory_selected_fileids'][$path] = $fileid;
  bratonien_tools_nc_wizard_store($state);
  return array('message'=>$path === '' ? 'Stammverzeichnis ausgewählt.' : 'Verzeichnis hinzugefügt.');
}

function bratonien_tools_nc_wizard_directory_remove()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 2 || empty($state['directory_selection_ready'])) throw new RuntimeException('Die Verzeichnisauswahl ist in diesem Fenster nicht verfügbar.');
  $path = trim((string)($_POST['nc_wizard_directory_remove'] ?? ''), '/');
  $selected = isset($state['directory_selected']) && is_array($state['directory_selected']) ? $state['directory_selected'] : array();
  $state['directory_selected'] = array_values(array_filter($selected, function($value) use ($path) { return (string)$value !== $path; }));
  if (isset($state['directory_selected_fileids'][$path])) unset($state['directory_selected_fileids'][$path]);
  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'Verzeichnis entfernt.');
}

function bratonien_tools_nc_wizard_save_mounts_server_side()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 2 || (string)$state['technical_stage'] !== 'mounts') throw new RuntimeException('Die Speicher-/Verzeichnisauswahl ist in diesem Fenster nicht verfügbar.');

  if (!empty($state['directory_selection_ready']))
  {
    bratonien_tools_nc_wizard_resolve_selected_roots($state);
    bratonien_tools_nc_wizard_store($state);
    return array('message'=>!empty($state['technical_complete']) ? 'Verzeichnisse und vorhandene Storage-Adapter wurden übernommen.' : 'Verzeichnisse wurden aufgelöst. Ein neuer Storage-Adapter muss noch zugeordnet werden.');
  }

  $candidates = isset($state['storage_candidates']) && is_array($state['storage_candidates']) ? $state['storage_candidates'] : array();
  if (!$candidates) throw new RuntimeException('Es wurden noch keine Speicherorte aus der Verzeichnisauswahl aufgelöst.');
  $mounts = isset($_POST['nc_wizard_storage_mount']) && is_array($_POST['nc_wizard_storage_mount']) ? $_POST['nc_wizard_storage_mount'] : array();

  foreach ($candidates as $index=>&$candidate)
  {
    $mount = rtrim(trim((string)($candidate['local_mount'] ?? '')), '/');
    if ($mount === '') $mount = rtrim(trim((string)($mounts[$index] ?? '')), '/');
    if ($mount === '' || $mount[0] !== '/') throw new RuntimeException('Für einen Speicherort fehlt ein gültiger lokaler Pfad.');
    if (!is_dir($mount) || !is_readable($mount)) throw new RuntimeException('Der angegebene Speicherort ist nicht vorhanden oder nicht lesbar: '.$mount);
    $candidate['local_mount'] = $mount;
  }
  unset($candidate);

  $state['storage_candidates'] = array_values($candidates);
  $state['storages'] = $state['storage_candidates'];
  $state['technical_complete'] = true;
  $state['technical_stage'] = 'ready';
  $state['technical_error'] = '';
  $state['directory_selection_ready'] = false;
  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'Storage-Adapter wurde geprüft. Die Verbindung ist technisch vollständig.');
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

function bratonien_tools_nc_wizard_back()
{
  $state = bratonien_tools_nc_wizard_state();
  $step = (int)($state['step'] ?? 1);
  if ($step <= 1) return array('message'=>'Bereits am Anfang des Assistenten.');

  if ($step === 4)
  {
    $state['step'] = 3;
  }
  elseif ($step === 3)
  {
    $state['step'] = 2;
    $state['technical_complete'] = true;
    $state['technical_stage'] = 'ready';
    $state['directory_selection_ready'] = false;
  }
  elseif (!empty($state['technical_complete']))
  {
    $state['technical_complete'] = false;
    $state['technical_stage'] = 'mounts';
    $state['directory_selection_ready'] = true;
    bratonien_tools_nc_wizard_refresh_directory_state($state);
  }
  elseif ((string)($state['technical_stage'] ?? '') === 'mounts' && !empty($state['directory_selection_ready']))
  {
    if (!empty($state['database_prompted'])) $state['technical_stage'] = 'database_details'; else $state['step'] = 1;
    $state['directory_selection_ready'] = false;
  }
  elseif ((string)($state['technical_stage'] ?? '') === 'mounts')
  {
    $state['directory_selection_ready'] = true;
    bratonien_tools_nc_wizard_refresh_directory_state($state);
  }
  elseif ((string)($state['technical_stage'] ?? '') === 'database_details')
  {
    $state['step'] = 1;
  }
  else
  {
    $state['step'] = 1;
  }

  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'Ein Fenster zurück.');
}
