#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

function fail_sync($message)
{
  throw new RuntimeException($message);
}

function http_error_detail($body)
{
  $body = trim((string)$body);
  if ($body === '') return '';

  $decoded = json_decode($body, true);
  if (is_array($decoded))
  {
    $detail = (string)($decoded['message'] ?? $decoded['err'] ?? '');
    if ($detail !== '') return $detail;
  }

  if (function_exists('simplexml_load_string'))
  {
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($xml !== false)
    {
      $detail = trim((string)($xml->message ?? $xml->err ?? ''));
      if ($detail !== '') return $detail;
    }
  }

  $plain = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
  return $plain === '' ? '' : mb_substr($plain, 0, 500);
}

function http_request($url, array $fields, array $headers = array(), $cookie_file = null)
{
  $ch = curl_init($url);
  $options = array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($fields),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 900,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_USERAGENT => 'Bratonien-NC-Connector',
  );
  if ($headers) $options[CURLOPT_HTTPHEADER] = $headers;
  if ($cookie_file !== null)
  {
    $options[CURLOPT_COOKIEJAR] = $cookie_file;
    $options[CURLOPT_COOKIEFILE] = $cookie_file;
  }
  curl_setopt_array($ch, $options);
  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false || $errno !== 0) fail_sync('HTTP-Aufruf fehlgeschlagen: '.$error);
  if ($http < 200 || $http >= 300)
  {
    $detail = http_error_detail((string)$body);
    fail_sync('HTTP-Aufruf antwortete mit Status '.$http.($detail !== '' ? ': '.$detail : '.'));
  }
  return (string)$body;
}

function xml_value_sync(SimpleXMLElement $node)
{
  $children = $node->children();
  if (count($children) === 0) return (string)$node;
  $result = array();
  foreach ($children as $name => $child)
  {
    $value = xml_value_sync($child);
    if (array_key_exists($name, $result))
    {
      if (!is_array($result[$name]) || !array_is_list($result[$name])) $result[$name] = array($result[$name]);
      $result[$name][] = $value;
    }
    else $result[$name] = $value;
  }
  return $result;
}

function decode_ws($body)
{
  $decoded = json_decode((string)$body, true);
  if (!is_array($decoded))
  {
    if (!function_exists('simplexml_load_string')) fail_sync('Piwigo lieferte XML, aber SimpleXML ist nicht verfuegbar.');
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string)$body);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($xml === false || $xml->getName() !== 'rsp') fail_sync('Piwigo lieferte weder gueltiges JSON noch eine gueltige XML-Webservice-Antwort.');
    $decoded = array('stat'=>(string)$xml['stat']);
    foreach ($xml->children() as $name => $child)
    {
      $value = xml_value_sync($child);
      if (array_key_exists($name, $decoded))
      {
        if (!is_array($decoded[$name]) || !array_is_list($decoded[$name])) $decoded[$name] = array($decoded[$name]);
        $decoded[$name][] = $value;
      }
      else $decoded[$name] = $value;
    }
  }
  if (($decoded['stat'] ?? '') !== 'ok')
  {
    $message = (string)($decoded['message'] ?? $decoded['err'] ?? 'Piwigo-Aufruf wurde abgelehnt.');
    fail_sync($message);
  }
  if (array_key_exists('result', $decoded)) return $decoded['result'];
  unset($decoded['stat']);
  return $decoded;
}

function decrypt_blob($blob, $hex_key)
{
  if (!preg_match('/^[a-f0-9]{64}$/', (string)$hex_key)) fail_sync('Connector-Schluessel ist ungueltig.');
  $outer = base64_decode(trim((string)$blob), true);
  $payload = is_string($outer) ? json_decode($outer, true) : null;
  if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) fail_sync('Gespeicherte Zugangsdaten haben ein unbekanntes Format.');
  $iv = base64_decode((string)($payload['iv'] ?? ''), true);
  $tag = base64_decode((string)($payload['tag'] ?? ''), true);
  $cipher = base64_decode((string)($payload['data'] ?? ''), true);
  $plain = openssl_decrypt($cipher, 'aes-256-gcm', hex2bin($hex_key), OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false) fail_sync('Gespeicherte Zugangsdaten konnten nicht entschluesselt werden.');
  return (string)$plain;
}

function ensure_webdav_site(mysqli $db, $prefixeTable, $piwigo_root, array $connection_config, $connection_id)
{
  if ((string)($connection_config['source_mode'] ?? '') !== 'webdav-placeholder') return 1;

  $gallery_root = rtrim((string)($connection_config['parallel_gallery_root'] ?? ''), '/');
  if ($gallery_root === '') fail_sync('WebDAV-Galeriewurzel fehlt in der Verbindungskonfiguration.');
  $piwigo_root = rtrim((string)$piwigo_root, '/');
  if (strpos($gallery_root, $piwigo_root.'/') !== 0)
  {
    fail_sync('WebDAV-Galeriewurzel liegt ausserhalb der Piwigo-Installation.');
  }
  if (!is_dir($gallery_root)) fail_sync('WebDAV-Galeriewurzel existiert nicht: '.$gallery_root);

  $relative = ltrim(substr($gallery_root, strlen($piwigo_root)), '/');
  if ($relative === '') fail_sync('WebDAV-Galeriewurzel darf nicht dem Piwigo-Hauptverzeichnis entsprechen.');
  $site_url = './'.rtrim($relative, '/').'/';
  $escaped = $db->real_escape_string($site_url);
  $result = $db->query("SELECT id FROM `{$prefixeTable}sites` WHERE galleries_url='{$escaped}' LIMIT 1");
  if (!$result) fail_sync('Piwigo-Site konnte nicht gelesen werden: '.$db->error);
  if ($result->num_rows)
  {
    return (int)$result->fetch_assoc()['id'];
  }

  if (!$db->query("INSERT INTO `{$prefixeTable}sites` (galleries_url) VALUES ('{$escaped}')"))
  {
    fail_sync('Piwigo-Site fuer WebDAV-Verbindung #'.$connection_id.' konnte nicht angelegt werden: '.$db->error);
  }
  $site_id = (int)$db->insert_id;
  if ($site_id < 1) fail_sync('Piwigo-Site fuer WebDAV-Verbindung #'.$connection_id.' erhielt keine gueltige ID.');
  return $site_id;
}

try
{
  $options = getopt('', array('piwigo-root:', 'connection-id:', 'base-url:'));
  $piwigo_root = rtrim((string)($options['piwigo-root'] ?? ''), '/');
  $connection_id = (int)($options['connection-id'] ?? 0);
  $base_url = rtrim((string)($options['base-url'] ?? 'http://127.0.0.1'), '/');
  if ($piwigo_root === '' || $connection_id < 1) fail_sync('Parameter --piwigo-root und --connection-id werden benoetigt.');
  if (!function_exists('curl_init')) fail_sync('PHP-cURL ist nicht verfuegbar.');

  $db_config = $piwigo_root.'/local/config/database.inc.php';
  if (!is_readable($db_config)) fail_sync('Piwigo-Datenbankkonfiguration ist nicht lesbar.');
  $conf = array();
  $prefixeTable = 'piwigo_';
  require $db_config;
  foreach (array('db_host','db_user','db_password','db_base') as $key) if (!isset($conf[$key])) fail_sync('Piwigo-Datenbankkonfiguration ist unvollstaendig: '.$key);

  $db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
  if ($db->connect_errno) fail_sync('Piwigo-Datenbank ist nicht erreichbar: '.$db->connect_error);
  $db->set_charset('utf8mb4');

  $key_result = $db->query("SELECT value FROM `{$prefixeTable}config` WHERE param='bratonien_nc_connector_secret' LIMIT 1");
  if (!$key_result || !$key_result->num_rows) fail_sync('Connector-Schluessel wurde nicht gefunden.');
  $hex_key = (string)$key_result->fetch_assoc()['value'];

  $connection_table = $prefixeTable.'bratonien_tools_nc_connections';
  $connection_result = $db->query('SELECT config_json, secret_blob FROM `'.$connection_table.'` WHERE id='.$connection_id.' LIMIT 1');
  if (!$connection_result || !$connection_result->num_rows) fail_sync('Connector-Verbindung #'.$connection_id.' wurde nicht gefunden.');
  $connection_row = $connection_result->fetch_assoc();
  $connection_config = json_decode((string)$connection_row['config_json'], true);
  if (!is_array($connection_config)) $connection_config = array();
  $connection_plain = decrypt_blob((string)$connection_row['secret_blob'], $hex_key);
  $connection_credentials = json_decode($connection_plain, true);
  if (!is_array($connection_credentials)) $connection_credentials = array();

  $site_id = ensure_webdav_site($db, $prefixeTable, $piwigo_root, $connection_config, $connection_id);

  $api = array('key_id'=>'', 'key_secret'=>'');
  $connection_scoped = (string)($connection_config['piwigo_auth'] ?? '') === 'connection-scoped' || array_key_exists('api_enabled', $connection_config);
  if ($connection_scoped)
  {
    if (!empty($connection_config['api_enabled']))
    {
      $api['key_id'] = trim((string)($connection_credentials['api_key_id'] ?? ''));
      $api['key_secret'] = trim((string)($connection_credentials['api_key_secret'] ?? ''));
    }
  }
  else
  {
    $api_result = $db->query("SELECT value FROM `{$prefixeTable}config` WHERE param='bratonien_nc_piwigo_api' LIMIT 1");
    if ($api_result && $api_result->num_rows)
    {
      $api_blob = (string)$api_result->fetch_assoc()['value'];
      if ($api_blob !== '')
      {
        $api_plain = decrypt_blob($api_blob, $hex_key);
        $api_decoded = json_decode($api_plain, true);
        if (is_array($api_decoded))
        {
          $api['key_id'] = (string)($api_decoded['key_id'] ?? '');
          $api['key_secret'] = (string)($api_decoded['key_secret'] ?? '');
        }
      }
    }
  }

  $fallback_user = (string)($connection_credentials['piwigo_user'] ?? '');
  $fallback_password = (string)($connection_credentials['piwigo_password'] ?? '');

  $api_error = null;
  if ($api['key_id'] !== '' && $api['key_secret'] !== '')
  {
    try
    {
      $headers = array(
        'X-PIWIGO-API: '.$api['key_id'].':'.$api['key_secret'],
        'Accept: application/json, text/xml;q=0.9',
        'Content-Type: application/x-www-form-urlencoded',
      );
      decode_ws(http_request($base_url.'/ws.php?format=json', array('method'=>'bratonien.nc.syncProductive', 'site_id'=>$site_id), $headers));
      $orphan = decode_ws(http_request($base_url.'/ws.php?format=json', array('method'=>'bratonien.nc.syncOrphans', 'site_id'=>$site_id, 'simulate'=>0), $headers));
      if ($site_id !== 1)
      {
        decode_ws(http_request($base_url.'/ws.php?format=json', array('method'=>'bratonien.nc.syncOrphans', 'site_id'=>1, 'simulate'=>0), $headers));
      }
      $added = (int)($orphan['added_orphans'] ?? 0);
      $deleted = (int)($orphan['deleted_orphans'] ?? 0);
      echo "Piwigo-Synchronisierung per API erfolgreich (Site $site_id)\n";
      echo "Piwigo-Orphans synchronisiert: +$added / -$deleted\n";
      exit(0);
    }
    catch (Throwable $e)
    {
      $api_error = $e->getMessage();
      fwrite(STDERR, "Piwigo-API nicht nutzbar: ".$api_error."\n");
    }
  }
  else
  {
    $api_error = $connection_scoped ? 'Fuer diese Verbindung ist keine API konfiguriert.' : 'Keine gespeicherten API-Zugangsdaten.';
    fwrite(STDERR, "Piwigo-API nicht nutzbar: ".$api_error."\n");
  }

  if ($fallback_user === '' || $fallback_password === '') fail_sync('Kein gespeicherter Benutzername/Passwort-Fallback fuer diese Verbindung vorhanden.');

  $cookie_file = tempnam(sys_get_temp_dir(), 'br-nc-sync-');
  if ($cookie_file === false) fail_sync('Temporare Piwigo-Sitzung konnte nicht angelegt werden.');

  try
  {
    decode_ws(http_request($base_url.'/ws.php?format=json', array('method'=>'pwg.session.login', 'username'=>$fallback_user, 'password'=>$fallback_password), array(), $cookie_file));

    // Der Fallback darf keinen zweiten Strukturpfad benutzen. Auch mit
    // Benutzer/Passwort wird exakt derselbe Bratonien-Sync wie mit API-Key
    // ausgefuehrt. Dadurch werden alte technische WebDAV-Kategorien entfernt
    // und vorhandene Piwigo-Alben wiederverwendet.
    decode_ws(http_request(
      $base_url.'/ws.php?format=json',
      array('method'=>'bratonien.nc.syncProductive', 'site_id'=>$site_id),
      array(),
      $cookie_file
    ));
    $orphan = decode_ws(http_request(
      $base_url.'/ws.php?format=json',
      array('method'=>'bratonien.nc.syncOrphans', 'site_id'=>$site_id, 'simulate'=>0),
      array(),
      $cookie_file
    ));
    if ($site_id !== 1)
    {
      decode_ws(http_request(
        $base_url.'/ws.php?format=json',
        array('method'=>'bratonien.nc.syncOrphans', 'site_id'=>1, 'simulate'=>0),
        array(),
        $cookie_file
      ));
    }
    $added = (int)($orphan['added_orphans'] ?? 0);
    $deleted = (int)($orphan['deleted_orphans'] ?? 0);
    echo "Piwigo-Datenbanksynchronisierung per Benutzername/Passwort-Fallback erfolgreich (Site $site_id)\n";
    echo "Piwigo-Orphans synchronisiert: +$added / -$deleted\n";
  }
  finally
  {
    @unlink($cookie_file);
  }
}
catch (Throwable $e)
{
  fwrite(STDERR, $e->getMessage()."\n");
  exit(1);
}
