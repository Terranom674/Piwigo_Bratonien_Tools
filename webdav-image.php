<?php
define('PHPWG_ROOT_PATH', '../../');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  define('BRATONIEN_TOOLS_ID', basename(__DIR__));
  define('BRATONIEN_TOOLS_PATH', PHPWG_ROOT_PATH.'plugins/'.BRATONIEN_TOOLS_ID.'/');
}
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_image_runtime.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/nc_transport.inc.php');

function bratonien_tools_webdav_image_abort($status, $message)
{
  http_response_code((int)$status);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store');
  echo $message;
  exit;
}

function bratonien_tools_webdav_image_ajax_response($image_id, $preview)
{
  $url = get_root_url().'plugins/'.BRATONIEN_TOOLS_ID.'/webdav-image.php?id='.(int)$image_id;
  if ($preview) $url .= '&preview=1';

  http_response_code(200);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, max-age=0');
  echo json_encode(array('url'=>$url), JSON_UNESCAPED_SLASHES);
  exit;
}

function bratonien_tools_webdav_image_decrypt_secret($blob, $hex_key)
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

function bratonien_tools_webdav_image_quote_path($path)
{
  $parts = array_values(array_filter(explode('/', trim((string)$path, '/')), 'strlen'));
  return implode('/', array_map('rawurlencode', $parts));
}

$image_id = (int)($_GET['id'] ?? 0);
if ($image_id < 1) bratonien_tools_webdav_image_abort(400, 'Bild-ID fehlt.');

$permission_condition = get_sql_condition_FandF(array('forbidden_categories'=>'category_id'), null, true);
$access_result = pwg_query('SELECT 1 FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id='.$image_id.' AND '.$permission_condition.' LIMIT 1');
if (!pwg_db_num_rows($access_result)) bratonien_tools_webdav_image_abort(403, 'Kein Zugriff auf dieses Bild.');

$source = bratonien_tools_webdav_image_source_info($image_id);
if (!$source) bratonien_tools_webdav_image_abort(404, 'Keine WebDAV-Quelle für dieses Bild gefunden.');

$preview_requested = !empty($_GET['preview']);

// Piwigos Standard-Lader erwartet bei ?ajaxload=true JSON und lädt erst die
// darin enthaltene URL als Bild. Binärdaten in diesem Request werden als
// Ladefehler behandelt und durch errors_small.png ersetzt.
if (isset($_GET['ajaxload']) && (string)$_GET['ajaxload'] === 'true')
{
  bratonien_tools_webdav_image_ajax_response($image_id, $preview_requested);
}

if ($preview_requested)
{
  $preview = bratonien_tools_webdav_preview_path($source);
  if ($preview)
  {
    $mtime = @filemtime($preview) ?: time();
    $etag = sha1($preview.'|'.$mtime.'|'.(@filesize($preview) ?: 0));
    header('Content-Type: '.bratonien_tools_webdav_preview_content_type($preview));
    header('Content-Length: '.(string)filesize($preview));
    header('ETag: "'.$etag.'"');
    header('Cache-Control: private, max-age=86400, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    $client_etag = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), " \t\r\n\"");
    if ($client_etag !== '' && hash_equals($etag, $client_etag))
    {
      http_response_code(304);
      exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($preview);
    exit;
  }
}

$table = $GLOBALS['prefixeTable'].'bratonien_tools_nc_connections';
$result = pwg_query('SELECT config_json, secret_blob FROM `'.$table.'` WHERE id='.(int)$source['connection_id'].' LIMIT 1');
if (!pwg_db_num_rows($result)) bratonien_tools_webdav_image_abort(404, 'WebDAV-Verbindung nicht gefunden.');
$row = pwg_db_fetch_assoc($result);
$config = json_decode((string)$row['config_json'], true);
if (!is_array($config)) bratonien_tools_webdav_image_abort(500, 'WebDAV-Konfiguration ist ungültig.');

$key_result = pwg_query("SELECT value FROM ".$GLOBALS['prefixeTable']."config WHERE param='bratonien_nc_connector_secret' LIMIT 1");
if (!pwg_db_num_rows($key_result)) bratonien_tools_webdav_image_abort(500, 'Connector-Schlüssel fehlt.');
$key_row = pwg_db_fetch_assoc($key_result);
$credentials = bratonien_tools_webdav_image_decrypt_secret((string)$row['secret_blob'], (string)$key_row['value']);
if (!is_array($credentials)) bratonien_tools_webdav_image_abort(500, 'WebDAV-Zugangsdaten konnten nicht gelesen werden.');

$base_url = rtrim((string)($config['nextcloud_url'] ?? ''), '/');
$user = trim((string)($credentials['nextcloud_user'] ?? ''));
$password = (string)($credentials['nextcloud_password'] ?? '');
$webdav_path = trim((string)$source['webdav_path'], '/');
if ($base_url === '' || $user === '' || $password === '' || $webdav_path === '')
{
  bratonien_tools_webdav_image_abort(500, 'WebDAV-Bildquelle ist unvollständig.');
}

$etag = trim((string)($source['etag'] ?? ''));
if ($etag !== '')
{
  header('ETag: "'.str_replace('"', '', $etag).'"');
  $client_etag = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), " \t\r\n\"");
  if ($client_etag !== '' && hash_equals($etag, $client_etag))
  {
    http_response_code(304);
    exit;
  }
}
header('Cache-Control: private, max-age=300, must-revalidate');
header('X-Content-Type-Options: nosniff');

$url = $base_url.'/remote.php/dav/files/'.rawurlencode($user).'/'.bratonien_tools_webdav_image_quote_path($webdav_path);
$ch = curl_init($url);
$options = array(
  CURLOPT_FOLLOWLOCATION => false,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_TIMEOUT => 120,
  CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
  CURLOPT_USERPWD => $user.':'.$password,
  CURLOPT_RETURNTRANSFER => false,
  CURLOPT_FAILONERROR => false,
  CURLOPT_USERAGENT => 'Bratonien-Tools-WebDAV-Image/0.9.7.15',
  CURLOPT_HEADERFUNCTION => function($ch, $line)
  {
    $length = strlen($line);
    $trimmed = trim($line);
    if ($trimmed === '') return $length;
    if (preg_match('#^HTTP/\S+\s+([0-9]{3})#i', $trimmed, $m))
    {
      http_response_code((int)$m[1]);
      return $length;
    }
    $colon = strpos($line, ':');
    if ($colon === false) return $length;
    $name = strtolower(trim(substr($line, 0, $colon)));
    $value = trim(substr($line, $colon + 1));
    if (in_array($name, array('content-type','content-length','content-range','accept-ranges','last-modified'), true))
    {
      header($name.': '.$value, true);
    }
    return $length;
  },
  CURLOPT_WRITEFUNCTION => function($ch, $data)
  {
    echo $data;
    return strlen($data);
  },
);
bratonien_tools_nc_transport_apply_curl($options, $url);
if (!empty($_SERVER['HTTP_RANGE']))
{
  $range = trim((string)$_SERVER['HTTP_RANGE']);
  if (preg_match('/^bytes=(.+)$/i', $range, $m)) $options[CURLOPT_RANGE] = $m[1];
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD')
{
  $options[CURLOPT_NOBODY] = true;
}
curl_setopt_array($ch, $options);
$ok = curl_exec($ch);
$errno = curl_errno($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($ok === false || $errno !== 0)
{
  if (!headers_sent()) bratonien_tools_webdav_image_abort(502, 'Nextcloud-Bild konnte nicht geladen werden.');
  exit;
}
if ($http < 200 || $http >= 400)
{
  exit;
}
