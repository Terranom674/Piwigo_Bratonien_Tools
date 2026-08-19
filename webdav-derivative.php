<?php
define('PHPWG_ROOT_PATH', '../../');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  define('BRATONIEN_TOOLS_ID', basename(__DIR__));
  define('BRATONIEN_TOOLS_PATH', PHPWG_ROOT_PATH.'plugins/'.BRATONIEN_TOOLS_ID.'/');
}

require_once(BRATONIEN_TOOLS_PATH.'include/webdav_image_runtime.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_gallery_runtime.inc.php');

function bratonien_tools_webdav_derivative_abort($status, $message)
{
  http_response_code((int)$status);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store');
  echo $message;
  exit;
}

function bratonien_tools_webdav_derivative_fallback($image_id)
{
  $url = bratonien_tools_webdav_image_url((int)$image_id, false);
  if (!$url)
  {
    bratonien_tools_webdav_derivative_abort(404, 'WebDAV-Bildquelle ist nicht verfuegbar.');
  }

  header('Cache-Control: no-store');
  header('Location: '.$url, true, 302);
  exit;
}

$image_id = (int)($_GET['id'] ?? 0);
$type = trim((string)($_GET['type'] ?? ''));
if ($image_id < 1 || $type === '')
{
  bratonien_tools_webdav_derivative_abort(400, 'Bild-ID oder Derivattyp fehlt.');
}

$permission_condition = get_sql_condition_FandF(array('forbidden_categories'=>'category_id'), null, true);
$access_result = pwg_query('SELECT 1 FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id='.$image_id.' AND '.$permission_condition.' LIMIT 1');
if (!pwg_db_num_rows($access_result))
{
  bratonien_tools_webdav_derivative_abort(403, 'Kein Zugriff auf dieses Bild.');
}

if (!class_exists('ImageStdParams') || !class_exists('DerivativeImage') || !class_exists('SrcImage'))
{
  require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');
}

$params = @ImageStdParams::get_by_type($type);
if (!$params)
{
  bratonien_tools_webdav_derivative_abort(404, 'Unbekannter Piwigo-Derivattyp.');
}

$result = pwg_query('SELECT * FROM '.IMAGES_TABLE.' WHERE id='.$image_id.' LIMIT 1');
if (!pwg_db_num_rows($result))
{
  bratonien_tools_webdav_derivative_abort(404, 'Bild wurde nicht gefunden.');
}
$row = pwg_db_fetch_assoc($result);
$src = new SrcImage($row);
$info = bratonien_tools_webdav_image_source_info($image_id);
if (!$info)
{
  bratonien_tools_webdav_derivative_abort(404, 'Keine WebDAV-Quelle fuer dieses Bild gefunden.');
}

$preview = bratonien_tools_webdav_preview_path($info);
if (!$preview || !is_file($preview) || !is_readable($preview))
{
  // Altbestand kann noch keinen vorbereiteten Preview-Cache besitzen. Der
  // fokussierte Fotorama-Request darf dann nicht mit 404 enden, weil Fotorama
  // den fehlgeschlagenen Frame sonst nicht erneut laedt. Stattdessen wird nur
  // fuer dieses angeforderte Bild auf die echte WebDAV-Quelle ausgewichen.
  bratonien_tools_webdav_derivative_fallback($image_id);
}

$derivative = new DerivativeImage($params, $src);
if ($derivative->same_as_source())
{
  // Niemals die lokale Connector-Platzhalterquelle ausliefern.
  bratonien_tools_webdav_derivative_fallback($image_id);
}

$target = $derivative->get_path();
if ($target === '')
{
  bratonien_tools_webdav_derivative_fallback($image_id);
}

if (!bratonien_tools_webdav_derivative_matches_preview($target, $params, $image_id))
{
  if (is_file($target))
  {
    @unlink($target);
    clearstatcache(true, $target);
  }

  $detail = '';
  if (!bratonien_tools_webdav_generate_derivative($params, $src, $detail))
  {
    error_log('Bratonien WebDAV on-demand derivative #'.$image_id.' type='.$type.': '.$detail);
    bratonien_tools_webdav_derivative_fallback($image_id);
  }
}

if (!bratonien_tools_webdav_derivative_matches_preview($target, $params, $image_id))
{
  bratonien_tools_webdav_derivative_fallback($image_id);
}

$size = @filesize($target);
$mtime = @filemtime($target) ?: time();
$etag = sha1($target.'|'.$mtime.'|'.($size ?: 0));
$extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
$content_type = 'image/jpeg';
if ($extension === 'png') $content_type = 'image/png';
elseif ($extension === 'gif') $content_type = 'image/gif';
elseif ($extension === 'webp') $content_type = 'image/webp';

header('Content-Type: '.$content_type);
if ($size !== false) header('Content-Length: '.(string)$size);
header('ETag: "'.$etag.'"');
header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');
header('Cache-Control: private, max-age=86400, must-revalidate');
header('X-Content-Type-Options: nosniff');

$client_etag = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), " \t\r\n\"");
if ($client_etag !== '' && hash_equals($etag, $client_etag))
{
  http_response_code(304);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD')
{
  readfile($target);
}
