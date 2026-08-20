<?php
define('PHPWG_ROOT_PATH', '../../');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  define('BRATONIEN_TOOLS_ID', basename(__DIR__));
  define('BRATONIEN_TOOLS_PATH', PHPWG_ROOT_PATH.'plugins/'.BRATONIEN_TOOLS_ID.'/');
}
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_image_runtime.inc.php');
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
    CURLOPT_USERAGENT => 'Bratonien-Tools-WebDAV-Derivative-Test/0.9.6.3',
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
    CURLOPT_USERAGENT => 'Bratonien-Tools-Derivative-Gate/0.9.6.3',
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
$src_image = new SrcImage($image_row);
$source = bratonien_tools_webdav_image_source_info($image_id);
if (!$source)
{
  bratonien_tools_webdav_derivative_test_abort(404, 'Keine WebDAV-Quelle fuer dieses Bild gefunden.');
}

$derivative = new DerivativeImage($defined[$type], $src_image);
if ($derivative->same_as_source())
{
  bratonien_tools_webdav_derivative_test_abort(409, 'Dieser Typ ist fuer das Bild identisch mit der Quelle und erzeugt kein Derivat.');
}
$derivative_path = $derivative->get_path();
if (is_file($derivative_path) && is_readable($derivative_path))
{
  header('X-Bratonien-WebDAV-Test: derivative-already-exists');
  header('Content-Type: '.(function_exists('mime_content_type') ? (mime_content_type($derivative_path) ?: 'application/octet-stream') : 'application/octet-stream'));
  header('Content-Length: '.filesize($derivative_path));
  header('Cache-Control: no-store');
  readfile($derivative_path);
  exit;
}

$image_path = (string)($image_row['path'] ?? '');
$absolute_image_path = $image_path;
if (strpos($absolute_image_path, '/') !== 0)
{
  $absolute_image_path = PHPWG_ROOT_PATH.ltrim(preg_replace('#^\./#', '', $absolute_image_path), '/');
}
$materialize_path = realpath($absolute_image_path);
if ($materialize_path === false || !is_file($materialize_path))
{
  bratonien_tools_webdav_derivative_test_abort(500, 'Placeholder-Quelldatei konnte nicht aufgeloest werden.');
}

$normalized_materialize = str_replace('\\', '/', $materialize_path);
if (!preg_match('#/nc-webdav-source/connection-'.(int)$source['connection_id'].'/#', $normalized_materialize))
{
  bratonien_tools_webdav_derivative_test_abort(500, 'Aufgeloester Placeholder-Pfad passt nicht zur WebDAV-Verbindung.');
}

$lock_path = $materialize_path.'.bratonien-materialize.lock';
$lock = @fopen($lock_path, 'c');
if (!$lock || !flock($lock, LOCK_EX))
{
  if ($lock) fclose($lock);
  bratonien_tools_webdav_derivative_test_abort(503, 'Materialisierungs-Lock konnte nicht gesetzt werden.');
}

$temp_path = $materialize_path.'.bratonien-real.'.getmypid().'.'.bin2hex(random_bytes(4)).'.part';
$backup_path = $materialize_path.'.bratonien-placeholder.'.getmypid().'.'.bin2hex(random_bytes(4));
$materialized = false;
$backup_created = false;
$cleaned = false;

$cleanup = function() use (&$cleaned, &$materialized, &$backup_created, $materialize_path, $backup_path, $temp_path, $lock)
{
  if ($cleaned) return;
  $cleaned = true;

  if ($materialized && $backup_created && is_file($backup_path))
  {
    @rename($backup_path, $materialize_path);
  }
  elseif ($backup_created && is_file($backup_path) && !is_file($materialize_path))
  {
    @rename($backup_path, $materialize_path);
  }

  if (is_file($temp_path)) @unlink($temp_path);
  if (is_file($backup_path)) @unlink($backup_path);

  @flock($lock, LOCK_UN);
  @fclose($lock);
};
register_shutdown_function($cleanup);

try
{
  $detail = '';
  if (!bratonien_tools_webdav_derivative_test_download($source, $temp_path, $detail))
  {
    bratonien_tools_webdav_derivative_test_abort(502, $detail);
  }

  // Preserve the exact placeholder inode via a second hard link. Replacing the
  // real source entry with rename() is then atomic and does not overwrite the
  // shared placeholder inode used by the WebDAV source tree.
  if (!@link($materialize_path, $backup_path))
  {
    bratonien_tools_webdav_derivative_test_abort(500, 'Placeholder konnte nicht sicher per Hardlink gesichert werden.');
  }
  $backup_created = true;

  if (!@rename($temp_path, $materialize_path))
  {
    bratonien_tools_webdav_derivative_test_abort(500, 'Nextcloud-Bild konnte nicht atomar materialisiert werden.');
  }
  $materialized = true;
  clearstatcache(true, $materialize_path);

  $inner_url = bratonien_tools_webdav_derivative_test_inner_url($derivative_path);
  if ($inner_url === null)
  {
    bratonien_tools_webdav_derivative_test_abort(500, 'Piwigo-i.php-URL konnte nicht bestimmt werden.');
  }

  $body = '';
  $content_type = 'application/octet-stream';
  $status = 500;
  if (!bratonien_tools_webdav_derivative_test_call_i($inner_url, $body, $content_type, $status, $detail))
  {
    bratonien_tools_webdav_derivative_test_abort(502, $detail."\n".$body);
  }

  // Restore before anything is sent to the client. The derivative itself stays
  // in Piwigo; only the transient Nextcloud original disappears again.
  $cleanup();
  clearstatcache(true, $derivative_path);
  if (!is_file($derivative_path) || !is_readable($derivative_path))
  {
    bratonien_tools_webdav_derivative_test_abort(500, 'i.php lieferte eine Antwort, aber das Piwigo-Derivat wurde nicht gefunden.');
  }

  header('X-Bratonien-WebDAV-Test: generated-by-piwigo-i.php');
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
