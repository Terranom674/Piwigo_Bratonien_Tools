<?php
define('PHPWG_ROOT_PATH', '../../');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  define('BRATONIEN_TOOLS_ID', basename(__DIR__));
  define('BRATONIEN_TOOLS_PATH', PHPWG_ROOT_PATH.'plugins/'.BRATONIEN_TOOLS_ID.'/');
}
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_materialize_runtime.inc.php');

function bratonien_tools_webdav_derivative_abort($status, $message)
{
  http_response_code((int)$status);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store');
  echo $message;
  exit;
}

function bratonien_tools_webdav_derivative_decrypt_secret($blob, $hex_key)
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

function bratonien_tools_webdav_derivative_quote_path($path)
{
  $parts = array_values(array_filter(explode('/', trim((string)$path, '/')), 'strlen'));
  return implode('/', array_map('rawurlencode', $parts));
}

function bratonien_tools_webdav_derivative_download(array $source, $destination, &$detail=null)
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
  $credentials = bratonien_tools_webdav_derivative_decrypt_secret((string)$row['secret_blob'], (string)$key_row['value']);
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

  $url = $base_url.'/remote.php/dav/files/'.rawurlencode($user).'/'.bratonien_tools_webdav_derivative_quote_path($webdav_path);
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
    CURLOPT_USERAGENT => 'Bratonien-Tools-WebDAV-Materialize',
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

function bratonien_tools_webdav_derivative_inner_url($derivative_path)
{
  $derivative_root = PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR;
  if (strpos($derivative_path, $derivative_root) !== 0) return null;

  $location = ltrim(substr($derivative_path, strlen($derivative_root)), '/');
  if ($location === '') return null;

  $segments = array_map('rawurlencode', explode('/', $location));
  return rtrim(get_absolute_root_url(), '/').'/i.php?/'.implode('/', $segments);
}

function bratonien_tools_webdav_derivative_call_i($url, &$status, &$detail=null)
{
  $detail = '';
  $status = 500;

  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FAILONERROR => false,
    CURLOPT_USERAGENT => 'Bratonien-Tools-WebDAV-Materialize-Gate',
    CURLOPT_WRITEFUNCTION => function($ch, $data)
    {
      return strlen($data);
    },
  ));

  $ok = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($ok === false || $errno !== 0)
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

function bratonien_tools_webdav_derivative_after_url($encoded)
{
  $encoded = trim((string)$encoded);
  if ($encoded === '') return null;

  $raw = strtr($encoded, '-_', '+/');
  $padding = strlen($raw) % 4;
  if ($padding) $raw .= str_repeat('=', 4 - $padding);
  $url = base64_decode($raw, true);
  if (!is_string($url) || $url === '' || preg_match('/[\r\n]/', $url)) return null;
  if (strpos($url, '//') === 0) return null;

  if (preg_match('#^https?://#i', $url))
  {
    $root = get_absolute_root_url();
    $root_parts = parse_url($root);
    $url_parts = parse_url($url);
    if (!is_array($root_parts) || !is_array($url_parts)) return null;
    if (strcasecmp((string)($root_parts['scheme'] ?? ''), (string)($url_parts['scheme'] ?? '')) !== 0) return null;
    if (strcasecmp((string)($root_parts['host'] ?? ''), (string)($url_parts['host'] ?? '')) !== 0) return null;
    if ((int)($root_parts['port'] ?? 0) !== (int)($url_parts['port'] ?? 0)) return null;
    return $url;
  }

  if (strpos($url, ':') !== false && !preg_match('#^[A-Za-z0-9_./?&=%+-]+$#', $url)) return null;
  return $url;
}

function bratonien_tools_webdav_derivative_serve($path)
{
  clearstatcache(true, $path);
  if (!is_file($path) || !is_readable($path))
  {
    bratonien_tools_webdav_derivative_abort(500, 'Piwigo-Derivat ist nicht lesbar.');
  }

  $size = @getimagesize($path);
  $content_type = is_array($size) && !empty($size['mime']) ? (string)$size['mime'] : 'application/octet-stream';
  $length = (int)filesize($path);

  header('Content-Type: '.$content_type);
  header('Content-Length: '.$length);
  header('Cache-Control: public, max-age=31536000');
  header('X-Content-Type-Options: nosniff');
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($path);
  exit;
}

$image_id = (int)($_GET['id'] ?? 0);
$variant = trim((string)($_GET['variant'] ?? ''));
$after_url = bratonien_tools_webdav_derivative_after_url($_GET['after'] ?? '');
$after_sig = trim((string)($_GET['sig'] ?? ''));
if ($image_id < 1) bratonien_tools_webdav_derivative_abort(400, 'Bild-ID fehlt.');
if ($variant === '') bratonien_tools_webdav_derivative_abort(400, 'Derivat-Variante fehlt.');
if ($after_url !== null)
{
  $expected_sig = bratonien_tools_webdav_materialize_after_signature($image_id, $variant, $after_url);
  if ($after_sig === '' || !hash_equals($expected_sig, $after_sig)) $after_url = null;
}

$permission_condition = get_sql_condition_FandF(array('forbidden_categories'=>'category_id'), null, true);
$access_result = pwg_query('SELECT 1 FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id='.$image_id.' AND '.$permission_condition.' LIMIT 1');
if (!pwg_db_num_rows($access_result)) bratonien_tools_webdav_derivative_abort(403, 'Kein Zugriff auf dieses Bild.');

$result = pwg_query('SELECT * FROM '.IMAGES_TABLE.' WHERE id='.$image_id.' LIMIT 1');
if (!pwg_db_num_rows($result)) bratonien_tools_webdav_derivative_abort(404, 'Bild nicht gefunden.');
$image_row = pwg_db_fetch_assoc($result);

$source = bratonien_tools_webdav_materialize_source_info($image_id);
if (!$source) bratonien_tools_webdav_derivative_abort(404, 'Keine WebDAV-Quelle fuer dieses Bild gefunden.');

$params = bratonien_tools_webdav_materialize_params_from_variant($variant);
if (!$params) bratonien_tools_webdav_derivative_abort(400, 'Unbekannte Derivat-Variante.');

$real_src = new SrcImage($image_row);
$real_derivative = new DerivativeImage($params, $real_src);
if ($real_derivative->same_as_source())
{
  $source_url = bratonien_tools_webdav_materialize_image_url($image_id);
  if (!$source_url) bratonien_tools_webdav_derivative_abort(404, 'WebDAV-Original konnte nicht bestimmt werden.');
  header('Location: '.$source_url, true, 302);
  exit;
}

$target_path = $real_derivative->get_path();
$derivative_root = PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR;
if ($target_path === '' || strpos($target_path, $derivative_root) !== 0)
{
  bratonien_tools_webdav_derivative_abort(500, 'Ungueltiger Piwigo-Derivatpfad.');
}
if (is_file($target_path) && is_readable($target_path))
{
  if ($after_url !== null)
  {
    header('Location: '.$after_url, true, 302);
    exit;
  }
  bratonien_tools_webdav_derivative_serve($target_path);
}

$upload_dir = rtrim((string)($GLOBALS['conf']['upload_dir'] ?? './upload'), '/');
$work_rel_dir = $upload_dir.'/bratonien-webdav-materialize';
$work_root = PHPWG_ROOT_PATH.$work_rel_dir;
if (!is_dir($work_root) && !@mkdir($work_root, 0755, true))
{
  bratonien_tools_webdav_derivative_abort(500, 'WebDAV-Materialisierungsbereich konnte nicht angelegt werden.');
}
if (!is_writable($work_root))
{
  bratonien_tools_webdav_derivative_abort(500, 'WebDAV-Materialisierungsbereich ist fuer PHP nicht beschreibbar.');
}

$lock_path = $work_root.'/image-'.$image_id.'-'.sha1($variant).'.lock';
$lock = @fopen($lock_path, 'c');
if (!$lock || !flock($lock, LOCK_EX))
{
  if ($lock) fclose($lock);
  bratonien_tools_webdav_derivative_abort(503, 'WebDAV-Materialisierungslock konnte nicht gesetzt werden.');
}

clearstatcache(true, $target_path);
if (is_file($target_path) && is_readable($target_path))
{
  @flock($lock, LOCK_UN);
  @fclose($lock);
  if ($after_url !== null)
  {
    header('Location: '.$after_url, true, 302);
    exit;
  }
  bratonien_tools_webdav_derivative_serve($target_path);
}

$extension = strtolower(pathinfo((string)($image_row['path'] ?? ''), PATHINFO_EXTENSION));
if (!in_array($extension, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) $extension = 'jpg';

$token = getmypid().'-'.bin2hex(random_bytes(6));
$source_filename = 'source-'.$image_id.'-'.$token.'.'.$extension;
$source_path = $work_root.'/'.$source_filename;
$source_rel = $work_rel_dir.'/'.$source_filename;
$temp_image_id = 0;
$temp_derivative_path = '';
$staging_target = '';
$cleaned = false;

$cleanup = function() use (&$cleaned, &$temp_image_id, &$temp_derivative_path, &$staging_target, $source_path, $lock)
{
  if ($cleaned) return;
  $cleaned = true;

  if ($temp_image_id > 0)
  {
    @pwg_query('DELETE FROM '.IMAGES_TABLE.' WHERE id='.(int)$temp_image_id.' LIMIT 1');
    $temp_image_id = 0;
  }

  if ($temp_derivative_path !== '' && is_file($temp_derivative_path)) @unlink($temp_derivative_path);
  if ($staging_target !== '' && is_file($staging_target)) @unlink($staging_target);
  if (is_file($source_path)) @unlink($source_path);

  @flock($lock, LOCK_UN);
  @fclose($lock);
};
register_shutdown_function($cleanup);

try
{
  $detail = '';
  if (!bratonien_tools_webdav_derivative_download($source, $source_path, $detail))
  {
    bratonien_tools_webdav_derivative_abort(502, $detail);
  }

  $dimensions = @getimagesize($source_path);
  if (!is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1]))
  {
    bratonien_tools_webdav_derivative_abort(500, 'Materialisiertes Original besitzt keine gueltigen Bildabmessungen.');
  }

  $temp_row = $image_row;
  unset($temp_row['id']);
  $temp_row['path'] = $source_rel;
  $temp_row['file'] = $source_filename;
  if (array_key_exists('width', $temp_row)) $temp_row['width'] = (int)$dimensions[0];
  if (array_key_exists('height', $temp_row)) $temp_row['height'] = (int)$dimensions[1];

  single_insert(IMAGES_TABLE, $temp_row);
  $temp_image_id = (int)pwg_db_insert_id();
  if ($temp_image_id < 1)
  {
    bratonien_tools_webdav_derivative_abort(500, 'Temporaerer Piwigo-Materialisierungseintrag konnte nicht angelegt werden.');
  }

  $temp_row['id'] = $temp_image_id;
  $temp_src = new SrcImage($temp_row);
  $temp_derivative = new DerivativeImage($params, $temp_src);
  if ($temp_derivative->same_as_source())
  {
    bratonien_tools_webdav_derivative_abort(500, 'Temporaeres Piwigo-Derivat ist unerwartet identisch mit der Quelle.');
  }

  $temp_derivative_path = $temp_derivative->get_path();
  if ($temp_derivative_path === '' || strpos($temp_derivative_path, $derivative_root) !== 0)
  {
    bratonien_tools_webdav_derivative_abort(500, 'Temporaerer Piwigo-Derivatpfad ist ungueltig.');
  }
  if (is_file($temp_derivative_path)) @unlink($temp_derivative_path);

  $inner_url = bratonien_tools_webdav_derivative_inner_url($temp_derivative_path);
  if ($inner_url === null)
  {
    bratonien_tools_webdav_derivative_abort(500, 'Piwigo-i.php-URL konnte nicht bestimmt werden.');
  }

  $inner_status = 500;
  if (!bratonien_tools_webdav_derivative_call_i($inner_url, $inner_status, $detail))
  {
    bratonien_tools_webdav_derivative_abort(502, $detail);
  }

  clearstatcache(true, $temp_derivative_path);
  $temp_size = @getimagesize($temp_derivative_path);
  if (!is_file($temp_derivative_path) || !is_readable($temp_derivative_path) || !is_array($temp_size))
  {
    bratonien_tools_webdav_derivative_abort(500, 'Piwigo hat kein gueltiges temporaeres Derivat erzeugt.');
  }

  clearstatcache(true, $target_path);
  if (!is_file($target_path))
  {
    $target_dir = dirname($target_path);
    $mode = $GLOBALS['conf']['chmod_value'] ?? 0755;
    if (!is_dir($target_dir) && !@mkdir($target_dir, $mode, true))
    {
      bratonien_tools_webdav_derivative_abort(500, 'Piwigo-Derivatverzeichnis konnte nicht angelegt werden.');
    }
    if (!is_writable($target_dir))
    {
      bratonien_tools_webdav_derivative_abort(500, 'Piwigo-Derivatverzeichnis ist nicht beschreibbar.');
    }

    $staging_target = $target_path.'.bratonien-'.$token.'.tmp';
    if (!@copy($temp_derivative_path, $staging_target))
    {
      bratonien_tools_webdav_derivative_abort(500, 'Piwigo-Derivat konnte nicht in den Zielbereich uebertragen werden.');
    }
    @chmod($staging_target, 0644);

    if (!@rename($staging_target, $target_path))
    {
      @unlink($staging_target);
      $staging_target = '';
      clearstatcache(true, $target_path);
      if (!is_file($target_path))
      {
        bratonien_tools_webdav_derivative_abort(500, 'Piwigo-Derivat konnte nicht atomar aktiviert werden.');
      }
    }
    $staging_target = '';
  }

  clearstatcache(true, $target_path);
  $final_size = @getimagesize($target_path);
  if (!is_file($target_path) || !is_readable($target_path) || !is_array($final_size))
  {
    bratonien_tools_webdav_derivative_abort(500, 'Finales Piwigo-Derivat ist nicht gueltig.');
  }

  $cleanup();
  if ($after_url !== null)
  {
    header('Location: '.$after_url, true, 302);
    exit;
  }
  bratonien_tools_webdav_derivative_serve($target_path);
}
finally
{
  $cleanup();
}
