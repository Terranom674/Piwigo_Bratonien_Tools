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

function bratonien_tools_nc_wizard_select_current_user()
{
  $state = bratonien_tools_nc_wizard_state();
  if (empty($state['scan_ok'])) throw new RuntimeException('Bitte zuerst Nextcloud erfolgreich scannen.');
  if (empty($state['technical_complete'])) throw new RuntimeException('Die Verbindung ist noch nicht vollständig geprüft.');
  $connection_name = trim((string)($_POST['nc_wizard_connection_name'] ?? $state['connection_name']));
  if ($connection_name === '') throw new RuntimeException('Bitte einen Namen für die Verbindung angeben.');
  $state['connection_name'] = $connection_name;
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
  else
  {
    $state['step'] = 1;
    $state['directory_selection_ready'] = false;
  }

  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'Ein Fenster zurück.');
}
