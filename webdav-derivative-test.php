<?php
define('PHPWG_ROOT_PATH', '../../');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');

function bratonien_tools_webdav_derivative_test_abort($status, $message)
{
  http_response_code((int)$status);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store');
  echo $message;
  exit;
}

function bratonien_tools_webdav_derivative_test_decrypt_secret($blob, $hex_key)
{
  $hex_key = trim((string)$hex_key);
  if (!preg_match('/^[a-f0-9]{64}$/', $hex_key)) return null;

  $outer = base64_decode(trim((string)$blob), true);
  $payload = is_string($outer) ? json_decode($outer, true) : null;
  if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) return null;

  $iv = base64_decode((string)($payload['iv'] ?? ''), true);
  $tag = base64_decode((string)($payload['tag'] ?? ''), true);
  $cipher = base64_decode((string)($payload['data'] ?? ''), true);
  if (!is_string($iv) || !is_string($tag) || !is_string($cipher)) return null;

  $plain = openssl_decrypt($cipher, 'aes-256-gcm', hex2bin($hex_key), OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false) return null;

  $decoded = json_decode((string)$plain, true);
  return is_array($decoded) ? $decoded : null;
}

function bratonien_tools_webdav_derivative_test_quote_path($path)
{
  $parts = array_values(array_filter(explode('/', trim((string)$path, '/')), 'strlen'));
  return implode('/', array_map('rawurlencode', $parts));
}

function bratonien_tools_webdav_derivative_test_source_info($image_id, array $image_row)
{
  $image_id = (int)$image_id;
  $path = (string)($image_row['path'] ?? '');
  if ($image_id < 1 || $path === '') return null;

  $absolute = $path;
  if (strpos($absolute, '/') !== 0)
  {
    $absolute = PHPWG_ROOT_PATH.ltrim(preg_replace('#^\\./#', '', $absolute), '/');
  }

  $resolved = realpath($absolute);
  if ($resolved === false) return null;

  $normalized = str_replace('\\\\', '/', $resolved);
  if (!preg_match('#/nc-webdav-source/connection-([0-9]+)/root-([0-9]+)/(.*)$#', $normalized, $match))
  {
    return null;
  }

  $connection_id = (int)$match[1];
  $root_fileid = (int)$match[2];
  $relative_path = trim((string)$match[3], '/');
  if ($connection_id < 1 || $root_fileid < 1 || $relative_path === '') return null;

  $table = $GLOBALS['prefixeTable'].'bratonien_tools_nc_connections';
  $connection_result = pwg_query('SELECT config_json FROM `'.$table.'` WHERE id='.$connection_id.' LIMIT 1');
  if (!pwg_db_num_rows($connection_result)) return null;

  $connection_row = pwg_db_fetch_assoc($connection_result);
  $config = json_decode((string)$connection_row['config_json'], true);
  if (!is_array($config) || (string)($config['source_mode'] ?? '') !== 'webdav-placeholder') return null;

  $root_found = false;
  $root_path = '';
  $roots = isset($config['roots']) && is_array($config['roots']) ? $config['roots'] : array();
  foreach ($roots as $root)
  {
    if ((int)($root['fileid'] ?? 0) === $root_fileid)
    {
      $root_found = true;
      $root_path = trim((string)($root['webdav_path'] ?? ''), '/');
      break;
    }
  }
  if (!$root_found) return null;

  $webdav_path = trim($root_path === '' ? $relative_path : $root_path.'/'.$relative_path, '/');
  if ($webdav_path === '') return null;

  $content_type = '';
  $size = 0;
  $etag = '';
  $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
  if ($state_dir !== '')
  {
    $mapping_file = $state_dir.'/webdav-map.json';
    if (is_readable($mapping_file))
    {
      $mapping = json_decode((string)file_get_contents($mapping_file), true);
      if (is_array($mapping) && isset($mapping['files']) && is_array($mapping['files']))
      {
        $entry = $mapping['files'][$resolved] ?? $mapping['files'][$normalized] ?? null;
        if (is_array($entry) && (string)($entry['kind'] ?? '') === 'file')
        {
          $webdav_path = trim((string)($entry['webdav_path'] ?? $webdav_path), '/');
          $content_type = (string)($entry['content_type'] ?? '');
          $size = (int)($entry['size'] ?? 0);
          $etag = (string)($entry['etag'] ?? '');
        }
      }
    }
  }

  return array(
    'image_id'=>$image_id,
    'connection_id'=>$connection_id,
    'webdav_path'=>$webdav_path,
    'content_type'=>$content_type,
    'size'=>$size,
    'etag'=>$etag,
    'coi'=>$image_row['coi'] ?? null,
  );
}

function bratonien_tools_webdav_derivative_test_download(array $source, $destination, &$detail=null)
{
  $detail = '';
  $table = $GLOBALS['prefixeTable'].'bratonien_tools_nc_connections';
  $result = pwg_query('SELECT config_json, secret_blob FROM `'.$table.'` WHERE id='.(int)$source['connection_id'].' LIMIT 1');
  if (!pwg_db_num_rows($result))
  {
    $detail = 'WebDAV-Verbindung nicht gefunden.';
    return false;
  }

  $row = pwg_db_fetch_assoc($result);
  $config = json_decode((string)$row['config_json'], true);
  if (!is_array($config))
  {
    $detail = 'WebDAV-Konfiguration ist ungueltig.';
    return false;
  }

  $key_result = pwg_query("SELECT value FROM ".$GLOBALS['prefixeTable']."config WHERE param='bratonien_nc_connector_secret' LIMIT 1");
  if (!pwg_db_num_rows($key_result))
  {
    $detail = 'Connector-Schluessel fehlt.';
    return false;
  }

  $key_row = pwg_db_fetch_assoc($key_result);
  $credentials = bratonien_tools_webdav_derivative_test_decrypt_secret((string)$row['secret_blob'], (string)$key_row['value']);
  if (!is_array($credentials))
  {
    $detail = 'WebDAV-Zugangsdaten konnten nicht gelesen werden.';
    return false;
  }

  $base_url = rtrim((string)($config['nextcloud_url'] ?? ''), '/');
  $user = trim((string)($credentials['nextcloud_user'] ?? ''));
  $password = (string)($credentials['nextcloud_password'] ?? '');
  $webdav_path = trim((string)($source['webdav_path'] ?? ''), '/');
  if ($base_url === '' || $user === '' || $password === '' || $webdav_path === '')
  {
    $detail = 'WebDAV-Bildquelle ist unvollstaendig.';
    return false;
  }

  $fp = @fopen($destination, 'xb');
  if (!$fp)
  {
    $detail = 'Temporaere Quelldatei konnte nicht angelegt werden.';
    return false;
  }

  $url = $base_url.'/remote.php/dav/files/'.rawurlencode($user).'/'.bratonien_tools_webdav_derivative_test_quote_path($webdav_path);
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $user.':'.$password,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FAILONERROR => false,
    CURLOPT_FILE => $fp,
    CURLOPT_USERAGENT => 'Bratonien-Tools-WebDAV-Derivative-Parallel-Test',
  ));

  $ok = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  fclose($fp);

  if ($ok === false || $errno !== 0 || $http < 200 || $http >= 300)
  {
    @unlink($destination);
    $detail = 'Nextcloud-Download fehlgeschlagen (HTTP '.$http.', cURL '.$errno.($error !== '' ? ': '.$error : '').').';
    return false;
  }

  if (!is_file($destination) || filesize($destination) < 1 || @getimagesize($destination) === false)
  {
    @unlink($destination);
    $detail = 'Nextcloud-Datei ist kein lesbares Bild.';
    return false;
  }

  return true;
}

function bratonien_tools_webdav_derivative_test_inner_url($derivative_path)
{
  $derivative_root = PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR;
  if (strpos($derivative_path, $derivative_root) !== 0) return null;

  $location = ltrim(substr($derivative_path, strlen($derivative_root)), '/');
  if ($location === '') return null;

  $segments = array_map('rawurlencode', explode('/', $location));
  return rtrim(get_absolute_root_url(), '/').'/i.php?/'.implode('/', $segments);
}

function bratonien_tools_webdav_derivative_test_call_i($url, &$response_body, &$response_type, &$response_status, &$detail=null)
{
  $detail = '';
  $response_body = '';
  $response_type = 'application/octet-stream';
  $response_status = 500;

  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FAILONERROR => false,
    CURLOPT_USERAGENT => 'Bratonien-Tools-Derivative-Parallel-Gate',
  ));

  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $content_type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  $response_status = $status;
  if ($content_type !== '') $response_type = $content_type;
  if (is_string($body)) $response_body = $body;

  if ($body === false || $errno !== 0)
  {
    $detail = 'Interner i.php-Aufruf fehlgeschlagen (cURL '.$errno.($error !== '' ? ': '.$error : '').').';
    return false;
  }
  if ($status < 200 || $status >= 400)
  {
    $detail = 'i.php antwortete mit HTTP '.$status.'.';
    return false;
  }

  return true;
}

global $user;
if (!isset($user['status']) || !in_array($user['status'], array('admin', 'webmaster'), true))
{
  bratonien_tools_webdav_derivative_test_abort(403, 'Dieser Test-Endpunkt ist nur fuer Administratoren verfuegbar.');
}

$image_id = (int)($_GET['id'] ?? 0);
if ($image_id < 1)
{
  bratonien_tools_webdav_derivative_test_abort(400, 'Bild-ID fehlt.');
}

$type = trim((string)($_GET['type'] ?? IMG_THUMB));
$defined = ImageStdParams::get_defined_type_map();
if (!isset($defined[$type]))
{
  bratonien_tools_webdav_derivative_test_abort(400, 'Unbekannter oder deaktivierter Derivat-Typ: '.$type);
}

$result = pwg_query('SELECT * FROM '.IMAGES_TABLE.' WHERE id='.$image_id.' LIMIT 1');
if (!pwg_db_num_rows($result))
{
  bratonien_tools_webdav_derivative_test_abort(404, 'Bild nicht gefunden.');
}
$image_row = pwg_db_fetch_assoc($result);

$source = bratonien_tools_webdav_derivative_test_source_info($image_id, $image_row);
if (!$source)
{
  bratonien_tools_webdav_derivative_test_abort(404, 'Keine WebDAV-Quelle fuer dieses Bild gefunden.');
}

$test_rel_dir = trim(PWG_DERIVATIVE_DIR, '/').'/bratonien-webdav-test';
$test_root = PHPWG_ROOT_PATH.$test_rel_dir;
if (!is_dir($test_root) && !@mkdir($test_root, 0755, true))
{
  bratonien_tools_webdav_derivative_test_abort(500, 'Paralleler Testbereich konnte nicht angelegt werden: '.$test_root);
}
if (!is_writable($test_root))
{
  bratonien_tools_webdav_derivative_test_abort(500, 'Paralleler Testbereich ist fuer PHP nicht beschreibbar: '.$test_root);
}

$lock_path = $test_root.'/image-'.$image_id.'.lock';
$lock = @fopen($lock_path, 'c');
if (!$lock || !flock($lock, LOCK_EX))
{
  if ($lock) fclose($lock);
  bratonien_tools_webdav_derivative_test_abort(503, 'Paralleler Test-Lock konnte nicht gesetzt werden.');
}

$extension = strtolower(pathinfo((string)($image_row['path'] ?? ''), PATHINFO_EXTENSION));
if (!in_array($extension, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true))
{
  $extension = 'jpg';
}

$token = getmypid().'-'.bin2hex(random_bytes(6));
$source_filename = 'source-'.$image_id.'-'.$token.'.'.$extension;
$source_path = $test_root.'/'.$source_filename;
$source_rel = './'.$test_rel_dir.'/'.$source_filename;
$temp_image_id = 0;
$derivative_path = '';
$cleaned = false;

$cleanup = function() use (&$cleaned, &$temp_image_id, &$derivative_path, $source_path, $lock)
{
  if ($cleaned) return;
  $cleaned = true;

  if ($temp_image_id > 0)
  {
    @pwg_query('DELETE FROM '.IMAGES_TABLE.' WHERE id='.(int)$temp_image_id.' LIMIT 1');
    $temp_image_id = 0;
  }

  if ($derivative_path !== '' && is_file($derivative_path))
  {
    @unlink($derivative_path);
  }

  if (is_file($source_path))
  {
    @unlink($source_path);
  }

  @flock($lock, LOCK_UN);
  @fclose($lock);
};
register_shutdown_function($cleanup);

try
{
  $detail = '';
  if (!bratonien_tools_webdav_derivative_test_download($source, $source_path, $detail))
  {
    bratonien_tools_webdav_derivative_test_abort(502, $detail);
  }

  $dimensions = @getimagesize($source_path);
  if (!is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1]))
  {
    bratonien_tools_webdav_derivative_test_abort(500, 'Paralleles Original besitzt keine gueltigen Bildabmessungen.');
  }

  $test_row = $image_row;
  unset($test_row['id']);
  $test_row['path'] = $source_rel;
  $test_row['file'] = $source_filename;
  if (array_key_exists('width', $test_row)) $test_row['width'] = (int)$dimensions[0];
  if (array_key_exists('height', $test_row)) $test_row['height'] = (int)$dimensions[1];

  single_insert(IMAGES_TABLE, $test_row);
  $temp_image_id = (int)pwg_db_insert_id();
  if ($temp_image_id < 1)
  {
    bratonien_tools_webdav_derivative_test_abort(500, 'Temporaerer Piwigo-Testeintrag konnte nicht angelegt werden.');
  }

  $test_row['id'] = $temp_image_id;
  $test_src = new SrcImage($test_row);
  $derivative = new DerivativeImage($defined[$type], $test_src);
  if ($derivative->same_as_source())
  {
    bratonien_tools_webdav_derivative_test_abort(409, 'Dieser Typ ist fuer das parallele Testbild identisch mit der Quelle.');
  }

  $derivative_path = $derivative->get_path();
  if ($derivative_path === '')
  {
    bratonien_tools_webdav_derivative_test_abort(500, 'Piwigo konnte keinen Derivat-Zielpfad fuer den parallelen Test bestimmen.');
  }

  if (is_file($derivative_path))
  {
    @unlink($derivative_path);
    clearstatcache(true, $derivative_path);
  }

  $inner_url = bratonien_tools_webdav_derivative_test_inner_url($derivative_path);
  if ($inner_url === null)
  {
    bratonien_tools_webdav_derivative_test_abort(500, 'Piwigo-i.php-URL konnte fuer den parallelen Test nicht bestimmt werden.');
  }

  $body = '';
  $content_type = 'application/octet-stream';
  $status = 500;
  if (!bratonien_tools_webdav_derivative_test_call_i($inner_url, $body, $content_type, $status, $detail))
  {
    bratonien_tools_webdav_derivative_test_abort(502, $detail."\n".$body);
  }

  clearstatcache(true, $derivative_path);
  if (!is_file($derivative_path) || !is_readable($derivative_path))
  {
    bratonien_tools_webdav_derivative_test_abort(
      500,
      "i.php antwortete, aber das parallele Piwigo-Derivat wurde nicht erzeugt.\n"
      .'Testquelle: '.$source_rel."\n"
      .'i.php: '.$inner_url
    );
  }

  $derivative_size = @getimagesize($derivative_path);
  if (!is_array($derivative_size) || empty($derivative_size[0]) || empty($derivative_size[1]))
  {
    bratonien_tools_webdav_derivative_test_abort(500, 'Das von i.php erzeugte parallele Derivat ist keine lesbare Bilddatei.');
  }

  $generated_width = (int)$derivative_size[0];
  $generated_height = (int)$derivative_size[1];

  $cleanup();

  header('X-Bratonien-WebDAV-Test: parallel-i.php-success');
  header('X-Bratonien-WebDAV-Test-Size: '.$generated_width.'x'.$generated_height);
  header('Content-Type: '.$content_type);
  header('Content-Length: '.strlen($body));
  header('Cache-Control: no-store');
  http_response_code($status);
  echo $body;
}
finally
{
  $cleanup();
}
