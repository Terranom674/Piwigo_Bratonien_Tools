<?php

function bratonien_tools_nc_transport_host($url)
{
  $host = trim((string)parse_url((string)$url, PHP_URL_HOST));
  if ($host === '') throw new RuntimeException('Die Nextcloud-Adresse enthält keinen gültigen Hostnamen oder keine IP-Adresse.');
  return trim($host, '[]');
}

function bratonien_tools_nc_transport_scheme($url)
{
  $scheme = strtolower(trim((string)parse_url((string)$url, PHP_URL_SCHEME)));
  if (!in_array($scheme, array('http','https'), true)) throw new RuntimeException('Nextcloud muss per HTTP oder HTTPS angesprochen werden.');
  return $scheme;
}

function bratonien_tools_nc_transport_is_ip($host)
{
  return filter_var(trim((string)$host, '[]'), FILTER_VALIDATE_IP) !== false;
}

function bratonien_tools_nc_transport_public_ip($host)
{
  static $cache = array();

  $host = strtolower(trim((string)$host, '[]'));
  if ($host === '') throw new RuntimeException('Für die Nextcloud-Verbindung fehlt der Hostname.');
  if (bratonien_tools_nc_transport_is_ip($host)) return $host;
  if (isset($cache[$host])) return $cache[$host];
  if (!function_exists('curl_init')) throw new RuntimeException('Der öffentliche DNS-Abgleich benötigt PHP-cURL.');

  $providers = array(
    array('host'=>'dns.google', 'ips'=>array('8.8.8.8','8.8.4.4'), 'url'=>'https://dns.google/resolve?name='.rawurlencode($host).'&type=A'),
    array('host'=>'cloudflare-dns.com', 'ips'=>array('1.1.1.1','1.0.0.1'), 'url'=>'https://cloudflare-dns.com/dns-query?name='.rawurlencode($host).'&type=A'),
  );

  foreach ($providers as $provider)
  {
    foreach ($provider['ips'] as $resolver_ip)
    {
      $ch = curl_init($provider['url']);
      $options = array(
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>array('Accept: application/dns-json'),
        CURLOPT_USERAGENT=>'Bratonien-Tools-DNS/0.9.7.1',
      );
      if (defined('CURLOPT_RESOLVE'))
      {
        $options[CURLOPT_RESOLVE] = array($provider['host'].':443:'.$resolver_ip);
      }
      curl_setopt_array($ch, $options);
      $body = curl_exec($ch);
      $errno = curl_errno($ch);
      $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($body === false || $errno !== 0 || $status < 200 || $status >= 300) continue;

      $decoded = json_decode((string)$body, true);
      if (!is_array($decoded) || !isset($decoded['Answer']) || !is_array($decoded['Answer'])) continue;
      foreach ($decoded['Answer'] as $answer)
      {
        if ((int)($answer['type'] ?? 0) !== 1) continue;
        $ip = trim((string)($answer['data'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) continue;
        return $cache[$host] = $ip;
      }
    }
  }

  throw new RuntimeException('Für '.$host.' konnte keine öffentliche IPv4-Adresse ermittelt werden.');
}

function bratonien_tools_nc_transport_resolve_entry($url)
{
  $scheme = bratonien_tools_nc_transport_scheme($url);
  $host = bratonien_tools_nc_transport_host($url);
  if (bratonien_tools_nc_transport_is_ip($host)) return null;

  $port = (int)parse_url((string)$url, PHP_URL_PORT);
  if ($port < 1) $port = $scheme === 'https' ? 443 : 80;
  $ip = bratonien_tools_nc_transport_public_ip($host);
  return $host.':'.$port.':'.$ip;
}

function bratonien_tools_nc_transport_apply_curl(array &$options, $url)
{
  $entry = bratonien_tools_nc_transport_resolve_entry($url);
  if ($entry !== null)
  {
    if (!defined('CURLOPT_RESOLVE')) throw new RuntimeException('Diese cURL-Version unterstützt keine direkte Host-zu-IP-Zuordnung.');
    $options[CURLOPT_RESOLVE] = array($entry);
  }
}

function bratonien_tools_nc_transport_http($url, $username = '', $password = '', array $headers = array())
{
  if (!function_exists('curl_init')) throw new RuntimeException('Der Server kann Nextcloud derzeit nicht per HTTP prüfen.');

  $ch = curl_init($url);
  $options = array(
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_MAXREDIRS=>3,
    CURLOPT_CONNECTTIMEOUT=>8,
    CURLOPT_TIMEOUT=>15,
    CURLOPT_HTTPHEADER=>array_merge(array('Accept: application/json'), $headers),
    CURLOPT_USERAGENT=>'Bratonien-Tools-NC-Wizard/0.9.7.1',
  );
  bratonien_tools_nc_transport_apply_curl($options, $url);

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
    throw new RuntimeException('Verbindung fehlgeschlagen'.($error !== '' ? ': '.$error : '.'));
  }

  return array('status'=>$status, 'body'=>(string)$body);
}

function bratonien_tools_nc_transport_webdav_list(array $state, $path = '')
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
  $options = array(
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CUSTOMREQUEST=>'PROPFIND',
    CURLOPT_POSTFIELDS=>$body,
    CURLOPT_HTTPHEADER=>array('Depth: 1','Content-Type: application/xml; charset=utf-8'),
    CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
    CURLOPT_USERPWD=>(string)$state['username'].':'.(string)$state['_password'],
    CURLOPT_CONNECTTIMEOUT=>8,
    CURLOPT_TIMEOUT=>20,
  );
  bratonien_tools_nc_transport_apply_curl($options, $url);
  curl_setopt_array($ch, $options);
  $response = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($response === false || $errno !== 0) throw new RuntimeException('Nextcloud-Verzeichnisse konnten nicht geladen werden'.($error !== '' ? ': '.$error : '.'));
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

function bratonien_tools_nc_transport_refresh_directory_state(array &$state, $path = null)
{
  if ($path === null) $path = (string)($state['directory_path'] ?? '');
  $listing = bratonien_tools_nc_transport_webdav_list($state, $path);
  $state['directory_path'] = (string)$listing['current'];
  $state['directory_parent'] = (string)$listing['parent'];
  $state['directory_children'] = (array)$listing['children'];
  $state['directory_current_fileid'] = (int)$listing['current_fileid'];
  $state['directory_fileids'] = (array)$listing['fileids'];
  if (!isset($state['directory_selected']) || !is_array($state['directory_selected'])) $state['directory_selected'] = array();
  if (!isset($state['directory_selected_fileids']) || !is_array($state['directory_selected_fileids'])) $state['directory_selected_fileids'] = array();
}

function bratonien_tools_nc_transport_wizard_directory_browse()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 2 || (string)$state['technical_stage'] !== 'mounts' || empty($state['directory_selection_ready'])) throw new RuntimeException('Die Verzeichnisauswahl ist in diesem Fenster nicht verfügbar.');
  $path = trim((string)($_POST['nc_wizard_directory_path'] ?? ''), '/');
  bratonien_tools_nc_transport_refresh_directory_state($state, $path);
  bratonien_tools_nc_wizard_store($state);
  return array('message'=>'Verzeichnis geöffnet.');
}

function bratonien_tools_nc_transport_edit_start()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection) throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $is_webdav = (string)$connection['adapter'] === 'remote'
    && (string)($config['source_mode'] ?? '') === 'webdav-placeholder';
  if (!$is_webdav) return bratonien_tools_nc_connector_edit_start();

  $credentials = bratonien_tools_nc_connector_scoped_secret($connection);
  $base_url = trim((string)($config['nextcloud_url'] ?? ''));
  $username = trim((string)($credentials['nextcloud_user'] ?? ''));
  if ($username === '') $username = trim((string)($config['nextcloud_access_user'] ?? $config['access_user'] ?? ''));
  $password = (string)($credentials['nextcloud_password'] ?? '');

  $roots = isset($config['roots']) && is_array($config['roots']) ? array_values($config['roots']) : array();
  $selected = array();
  $selected_ids = array();
  foreach ($roots as $root)
  {
    $path = trim((string)($root['webdav_path'] ?? ''), '/');
    $fileid = (int)($root['fileid'] ?? 0);
    if ($fileid < 1) continue;
    $selected[] = $path;
    $selected_ids[$path] = $fileid;
  }

  $state = bratonien_tools_nc_wizard_state();
  $state = array_merge($state, array(
    'editing_connection_id'=>$id,
    'editing_adapter'=>(string)$connection['adapter'],
    'editing_mode'=>'update',
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
      bratonien_tools_nc_transport_refresh_directory_state($state, '');
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
  return array('message'=>'Verbindung #'.$id.' wurde zum Bearbeiten geöffnet.');
}
