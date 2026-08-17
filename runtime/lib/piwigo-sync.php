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
    CURLOPT_USERAGENT => 'Bratonien-NC-Connector/0.9.3.17',
  );
  if ($headers)
  {
    $options[CURLOPT_HTTPHEADER] = $headers;
  }
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

  if ($body === false || $errno !== 0)
  {
    fail_sync('HTTP-Aufruf fehlgeschlagen: '.$error);
  }
  if ($http < 200 || $http >= 300)
  {
    fail_sync('HTTP-Aufruf antwortete mit Status '.$http.'.');
  }

  return (string)$body;
}

function decode_ws($body)
{
  $decoded = json_decode((string)$body, true);
  if (!is_array($decoded))
  {
    fail_sync('Piwigo lieferte keine gueltige JSON-Antwort.');
  }
  if (($decoded['stat'] ?? '') !== 'ok')
  {
    $message = (string)($decoded['message'] ?? $decoded['err'] ?? 'Piwigo-Aufruf wurde abgelehnt.');
    fail_sync($message);
  }
  return $decoded['result'] ?? array();
}

function decrypt_blob($blob, $hex_key)
{
  if (!preg_match('/^[a-f0-9]{64}$/', (string)$hex_key))
  {
    fail_sync('Connector-Schluessel ist ungueltig.');
  }
  $outer = base64_decode(trim((string)$blob), true);
  $payload = is_string($outer) ? json_decode($outer, true) : null;
  if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1)
  {
    fail_sync('Gespeicherte Zugangsdaten haben ein unbekanntes Format.');
  }
  $iv = base64_decode((string)($payload['iv'] ?? ''), true);
  $tag = base64_decode((string)($payload['tag'] ?? ''), true);
  $cipher = base64_decode((string)($payload['data'] ?? ''), true);
  $plain = openssl_decrypt($cipher, 'aes-256-gcm', hex2bin($hex_key), OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false)
  {
    fail_sync('Gespeicherte Zugangsdaten konnten nicht entschluesselt werden.');
  }
  return (string)$plain;
}

try
{
  $options = getopt('', array('piwigo-root:', 'connection-id:', 'base-url:'));
  $piwigo_root = rtrim((string)($options['piwigo-root'] ?? ''), '/');
  $connection_id = (int)($options['connection-id'] ?? 0);
  $base_url = rtrim((string)($options['base-url'] ?? 'http://127.0.0.1'), '/');

  if ($piwigo_root === '' || $connection_id < 1)
  {
    fail_sync('Parameter --piwigo-root und --connection-id werden benoetigt.');
  }
  if (!function_exists('curl_init'))
  {
    fail_sync('PHP-cURL ist nicht verfuegbar.');
  }

  $db_config = $piwigo_root.'/local/config/database.inc.php';
  if (!is_readable($db_config))
  {
    fail_sync('Piwigo-Datenbankkonfiguration ist nicht lesbar.');
  }

  $conf = array();
  $prefixeTable = 'piwigo_';
  require $db_config;
  foreach (array('db_host','db_user','db_password','db_base') as $key)
  {
    if (!isset($conf[$key]))
    {
      fail_sync('Piwigo-Datenbankkonfiguration ist unvollstaendig: '.$key);
    }
  }

  $db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
  if ($db->connect_errno)
  {
    fail_sync('Piwigo-Datenbank ist nicht erreichbar: '.$db->connect_error);
  }
  $db->set_charset('utf8mb4');

  $key_result = $db->query("SELECT value FROM `{$prefixeTable}config` WHERE param='bratonien_nc_connector_secret' LIMIT 1");
  if (!$key_result || !$key_result->num_rows)
  {
    fail_sync('Connector-Schluessel wurde nicht gefunden.');
  }
  $hex_key = (string)$key_result->fetch_assoc()['value'];

  $api = array('key_id'=>'', 'key_secret'=>'');
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

  $connection_table = $prefixeTable.'bratonien_tools_nc_connections';
  $connection_result = $db->query('SELECT secret_blob FROM `'.$connection_table.'` WHERE id='.$connection_id.' LIMIT 1');
  if (!$connection_result || !$connection_result->num_rows)
  {
    fail_sync('Connector-Verbindung #'.$connection_id.' wurde nicht gefunden.');
  }
  $connection_blob = (string)$connection_result->fetch_assoc()['secret_blob'];
  $connection_plain = decrypt_blob($connection_blob, $hex_key);
  $connection_credentials = json_decode($connection_plain, true);
  if (!is_array($connection_credentials))
  {
    $connection_credentials = array();
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
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
      );
      decode_ws(http_request(
        $base_url.'/ws.php?format=json',
        array('method'=>'bratonien.nc.syncProductive', 'site_id'=>1),
        $headers
      ));
      $orphan = decode_ws(http_request(
        $base_url.'/ws.php?format=json',
        array('method'=>'bratonien.nc.syncOrphans', 'site_id'=>1, 'simulate'=>0),
        $headers
      ));
      $added = (int)($orphan['added_orphans'] ?? 0);
      $deleted = (int)($orphan['deleted_orphans'] ?? 0);
      echo "Piwigo-Synchronisierung per API erfolgreich\n";
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
    $api_error = 'Keine gespeicherten API-Zugangsdaten.';
    fwrite(STDERR, "Piwigo-API nicht nutzbar: ".$api_error."\n");
  }

  if ($fallback_user === '' || $fallback_password === '')
  {
    fail_sync('Kein gespeicherter Benutzername/Passwort-Fallback vorhanden.');
  }

  $cookie_file = tempnam(sys_get_temp_dir(), 'br-nc-sync-');
  if ($cookie_file === false)
  {
    fail_sync('Temporare Piwigo-Sitzung konnte nicht angelegt werden.');
  }

  try
  {
    $login = decode_ws(http_request(
      $base_url.'/ws.php?format=json',
      array('method'=>'pwg.session.login', 'username'=>$fallback_user, 'password'=>$fallback_password),
      array(),
      $cookie_file
    ));

    http_request(
      $base_url.'/admin.php?page=site_update&site=1',
      array(
        'sync'=>'files',
        'display_info'=>1,
        'privacy_level'=>0,
        'sync_meta'=>1,
        'simulate'=>0,
        'subcats-included'=>1,
        'bratonien_connector'=>1,
        'submit'=>1,
      ),
      array(),
      $cookie_file
    );

    $orphan = decode_ws(http_request(
      $base_url.'/ws.php?format=json',
      array('method'=>'bratonien.nc.syncOrphans', 'site_id'=>1, 'simulate'=>0),
      array(),
      $cookie_file
    ));
    $added = (int)($orphan['added_orphans'] ?? 0);
    $deleted = (int)($orphan['deleted_orphans'] ?? 0);

    echo "Piwigo-Datenbanksynchronisierung per Benutzername/Passwort-Fallback erfolgreich\n";
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
