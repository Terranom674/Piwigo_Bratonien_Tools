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

function bratonien_tools_webdav_derivative_debug($request_id, $event, array $fields=array())
{
  $parts = array('[BRAT-WD '.$request_id.']', $event);
  foreach ($fields as $key => $value)
  {
    if (is_bool($value)) $value = $value ? '1' : '0';
    elseif ($value === null) $value = 'NULL';
    elseif (is_array($value) || is_object($value)) $value = json_encode($value);
    $parts[] = $key.'='.str_replace(array("\r", "\n"), array('\\r', '\\n'), (string)$value);
  }
  error_log(implode(' ', $parts));
}

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
  if (!pwg_db_num_rows($result)) { $detail = 'WebDAV-Verbindung nicht gefunden.'; return false; }
  $row = pwg_db_fetch_assoc($result);
  $config = json_decode((string)$row['config_json'], true);
  if (!is_array($config)) { $detail = 'WebDAV-Konfiguration ist ungueltig.'; return false; }
  $key_result = pwg_query("SELECT value FROM ".$GLOBALS['prefixeTable']."config WHERE param='bratonien_nc_connector_secret' LIMIT 1");
  if (!pwg_db_num_rows($key_result)) { $detail = 'Connector-Schluessel fehlt.'; return false; }
  $key_row = pwg_db_fetch_assoc($key_result);
  $credentials = bratonien_tools_webdav_derivative_decrypt_secret((string)$row['secret_blob'], (string)$key_row['value']);
  if (!is_array($credentials)) { $detail = 'WebDAV-Zugangsdaten konnten nicht gelesen werden.'; return false; }

  $base_url = rtrim((string)($config['nextcloud_url'] ?? ''), '/');
  $user = trim((string)($credentials['nextcloud_user'] ?? ''));
  $password = (string)($credentials['nextcloud_password'] ?? '');
  $webdav_path = trim((string)($source['webdav_path'] ?? ''), '/');
  if ($base_url === '' || $user === '' || $password === '' || $webdav_path === '') { $detail = 'WebDAV-Bildquelle ist unvollstaendig.'; return false; }

  $fp = @fopen($destination, 'xb');
  if (!$fp) { $detail = 'Temporaere Quelldatei konnte nicht angelegt werden.'; return false; }

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

function bratonien_tools_webdav_derivative_make_dir($dir)
{
  global $conf;
  if (!is_dir($dir))
  {
    $mode = isset($conf['chmod_value']) ? (int)$conf['chmod_value'] : 0755;
    $umask = umask(0);
    $ok = @mkdir($dir, $mode, true);
    umask($umask);
    if (!$ok && !is_dir($dir)) return false;
  }
  if (!is_writable($dir)) return false;
  $index = rtrim($dir, '/').'/index.htm';
  if (!file_exists($index)) @file_put_contents($index, 'Not allowed!');
  return true;
}

function bratonien_tools_webdav_derivative_generate($source_path, $target_path, $params, array $image_row, &$detail=null)
{
  global $conf;
  $detail = '';
  include_once(PHPWG_ROOT_PATH.'admin/include/image.class.php');

  if (!is_object($params)) { $detail = 'Piwigo-Derivatparameter fehlen.'; return false; }
  if (!is_file($source_path) || !is_readable($source_path)) { $detail = 'Materialisiertes Original ist nicht lesbar.'; return false; }
  if (!bratonien_tools_webdav_derivative_make_dir(dirname($target_path))) { $detail = 'Piwigo-Derivatverzeichnis konnte nicht angelegt werden.'; return false; }

  $rotation_angle = isset($image_row['rotation'])
    ? pwg_image::get_rotation_angle_from_code($image_row['rotation'])
    : pwg_image::get_rotation_angle($source_path);
  $coi = $image_row['coi'] ?? null;

  if (($params->type ?? '') === IMG_CUSTOM)
  {
    $defined = ImageStdParams::get_defined_type_map();
    if (count($defined) > 0)
    {
      $sharpen = 0;
      foreach ($defined as $std_params) $sharpen += $std_params->sharpen;
      $params->sharpen = round($sharpen / count($defined));
    }
  }

  $image = null;
  $wm_image = null;
  try
  {
    $image = new pwg_image($source_path);
    $changes = 0;

    if (0 != $rotation_angle)
    {
      $image->rotate($rotation_angle);
      $changes++;
    }

    $o_size = $d_size = array($image->get_width(), $image->get_height());
    $crop_rect = null;
    $scaled_size = null;
    $params->sizing->compute($o_size, $coi, $crop_rect, $scaled_size);
    if ($crop_rect)
    {
      $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
      $changes++;
    }
    if ($scaled_size)
    {
      $image->resize($scaled_size[0], $scaled_size[1]);
      $d_size = $scaled_size;
      $changes++;
    }
    if ($params->sharpen)
    {
      $changes += $image->sharpen($params->sharpen);
    }

    if ($params->will_watermark($d_size))
    {
      $wm = ImageStdParams::get_watermark();
      $wm_image = new pwg_image(PHPWG_ROOT_PATH.$wm->file);
      $wm_size = array($wm_image->get_width(), $wm_image->get_height());
      if ($d_size[0] < $wm_size[0] || $d_size[1] < $wm_size[1])
      {
        $wm_scaling_params = SizingParams::classic($d_size[0], $d_size[1]);
        $tmp = null;
        $wm_scaled_size = null;
        $wm_scaling_params->compute($wm_size, null, $tmp, $wm_scaled_size);
        $wm_size = $wm_scaled_size;
        $wm_image->resize($wm_scaled_size[0], $wm_scaled_size[1]);
      }
      $x = round(($wm->xpos / 100) * ($d_size[0] - $wm_size[0]));
      $y = round(($wm->ypos / 100) * ($d_size[1] - $wm_size[1]));
      if ($image->compose($wm_image, $x, $y, $wm->opacity))
      {
        $changes++;
        if ($wm->xrepeat || $wm->yrepeat)
        {
          $xpad = $wm_size[0] + max(30, round($wm_size[0] / 4));
          $ypad = $wm_size[1] + max(30, round($wm_size[1] / 4));
          for ($i = -$wm->xrepeat; $i <= $wm->xrepeat; $i++)
          {
            for ($j = -$wm->yrepeat; $j <= $wm->yrepeat; $j++)
            {
              if (!$i && !$j) continue;
              $x2 = $x + $i * $xpad;
              $y2 = $y + $j * $ypad;
              if ($x2 >= 0 && $x2 + $wm_size[0] < $d_size[0] && $y2 >= 0 && $y2 + $wm_size[1] < $d_size[1])
              {
                if (!$image->compose($wm_image, $x2, $y2, $wm->opacity)) break;
              }
            }
          }
        }
      }
      $wm_image->destroy();
      $wm_image = null;
    }

    if (!$changes)
    {
      $detail = 'Piwigo-Derivat benoetigt keine Bildaenderung.';
      return false;
    }

    if ($d_size[0] * $d_size[1] < ($conf['derivatives_strip_metadata_threshold'] ?? PHP_INT_MAX))
    {
      $image->strip();
    }

    $image->write($target_path);
    @chmod($target_path, 0644);
    clearstatcache(true, $target_path);
    if (!is_file($target_path) || !is_readable($target_path) || @getimagesize($target_path) === false)
    {
      $detail = 'Piwigo hat kein gueltiges finales Derivat erzeugt.';
      return false;
    }
    return true;
  }
  catch (Throwable $e)
  {
    $detail = 'Piwigo-Derivaterzeugung fehlgeschlagen: '.$e->getMessage();
    return false;
  }
  finally
  {
    if ($wm_image) $wm_image->destroy();
    if ($image) $image->destroy();
  }
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
    $root_parts = parse_url(get_absolute_root_url());
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

function bratonien_tools_webdav_derivative_needs_after_redirect($url)
{
  if (!is_string($url) || $url === '') return false;
  return strpos($url, '/plugins/'.BRATONIEN_TOOLS_ID.'/watermark.php') !== false
    || strpos($url, 'plugins/'.BRATONIEN_TOOLS_ID.'/watermark.php') === 0;
}

function bratonien_tools_webdav_derivative_serve($path)
{
  clearstatcache(true, $path);
  if (!is_file($path) || !is_readable($path)) bratonien_tools_webdav_derivative_abort(500, 'Piwigo-Derivat ist nicht lesbar.');
  $size = @getimagesize($path);
  $content_type = is_array($size) && !empty($size['mime']) ? (string)$size['mime'] : 'application/octet-stream';
  header('Content-Type: '.$content_type);
  header('Content-Length: '.(int)filesize($path));
  header('Cache-Control: public, max-age=31536000');
  header('X-Content-Type-Options: nosniff');
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($path);
  exit;
}

$image_id = (int)($_GET['id'] ?? 0);
$variant = trim((string)($_GET['variant'] ?? ''));
$request_id = bin2hex(random_bytes(4));
bratonien_tools_webdav_derivative_debug($request_id, 'start', array('image_id'=>$image_id, 'variant'=>$variant));

$after_url = bratonien_tools_webdav_derivative_after_url($_GET['after'] ?? '');
$after_sig = trim((string)($_GET['sig'] ?? ''));
if ($image_id < 1) bratonien_tools_webdav_derivative_abort(400, 'Bild-ID fehlt.');
if ($variant === '') bratonien_tools_webdav_derivative_abort(400, 'Derivat-Variante fehlt.');
if ($after_url !== null)
{
  $expected_sig = bratonien_tools_webdav_materialize_after_signature($image_id, $variant, $after_url);
  if ($after_sig === '' || !hash_equals($expected_sig, $after_sig)) $after_url = null;
}
$redirect_after = bratonien_tools_webdav_derivative_needs_after_redirect($after_url) ? $after_url : null;

$permission_condition = get_sql_condition_FandF(array('forbidden_categories'=>'category_id'), null, true);
$access_result = pwg_query('SELECT 1 FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id='.$image_id.' AND '.$permission_condition.' LIMIT 1');
if (!pwg_db_num_rows($access_result)) bratonien_tools_webdav_derivative_abort(403, 'Kein Zugriff auf dieses Bild.');
$result = pwg_query('SELECT * FROM '.IMAGES_TABLE.' WHERE id='.$image_id.' LIMIT 1');
if (!pwg_db_num_rows($result)) bratonien_tools_webdav_derivative_abort(404, 'Bild nicht gefunden.');
$image_row = pwg_db_fetch_assoc($result);

$raw_source_path = (string)($image_row['path'] ?? '');
$absolute_source_path = $raw_source_path;
if ($absolute_source_path !== '' && strpos($absolute_source_path, '/') !== 0)
{
  $absolute_source_path = PHPWG_ROOT_PATH.ltrim(preg_replace('#^\./#', '', $absolute_source_path), '/');
}
bratonien_tools_webdav_derivative_debug($request_id, 'source_before_resolve', array(
  'path'=>$raw_source_path,
  'is_link'=>$absolute_source_path !== '' && is_link($absolute_source_path),
  'realpath'=>$absolute_source_path !== '' ? (realpath($absolute_source_path) ?: 'FALSE') : 'EMPTY',
));

$source = bratonien_tools_webdav_materialize_source_info($image_id);
if (!$source)
{
  bratonien_tools_webdav_derivative_debug($request_id, 'source_resolve_failed');
  bratonien_tools_webdav_derivative_abort(404, 'Keine WebDAV-Quelle fuer dieses Bild gefunden.');
}
bratonien_tools_webdav_derivative_debug($request_id, 'source_resolved', array('connection_id'=>$source['connection_id'] ?? 0, 'root_fileid'=>$source['root_fileid'] ?? 0, 'webdav_path'=>$source['webdav_path'] ?? ''));

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
if ($target_path === '' || strpos($target_path, $derivative_root) !== 0) bratonien_tools_webdav_derivative_abort(500, 'Ungueltiger Piwigo-Derivatpfad.');
if (is_file($target_path) && is_readable($target_path))
{
  bratonien_tools_webdav_derivative_debug($request_id, 'target_exists', array('target'=>$target_path));
  if ($redirect_after !== null) { header('Location: '.$redirect_after, true, 302); exit; }
  bratonien_tools_webdav_derivative_serve($target_path);
}

$shadow_path = $raw_source_path;
if ($shadow_path === '') bratonien_tools_webdav_derivative_abort(500, 'Piwigo-Quellpfad fehlt.');
if (strpos($shadow_path, '/') !== 0) $shadow_path = PHPWG_ROOT_PATH.ltrim(preg_replace('#^\./#', '', $shadow_path), '/');
$shadow_normalized = str_replace('\\', '/', $shadow_path);
if (!preg_match('#/_data/bratonien-tools/nc-webdav-gallery/connection-'.(int)$source['connection_id'].'/#', $shadow_normalized)) bratonien_tools_webdav_derivative_abort(500, 'Piwigo-Quellpfad liegt nicht im WebDAV-Shadow-Baum.');
if (!is_link($shadow_path)) bratonien_tools_webdav_derivative_abort(500, 'WebDAV-Shadow-Quelle ist kein Placeholder-Symlink.');

$placeholder_target = realpath($shadow_path);
$placeholder_normalized = $placeholder_target === false ? '' : str_replace('\\', '/', $placeholder_target);
if ($placeholder_target === false || !preg_match('#/nc-webdav-source/connection-'.(int)$source['connection_id'].'/root-'.(int)$source['root_fileid'].'/#', $placeholder_normalized)) bratonien_tools_webdav_derivative_abort(500, 'WebDAV-Placeholder-Ziel ist ungueltig.');

$shadow_dir = dirname($shadow_path);
if (!is_dir($shadow_dir) || !is_writable($shadow_dir)) bratonien_tools_webdav_derivative_abort(500, 'WebDAV-Shadow-Verzeichnis ist fuer PHP nicht beschreibbar.');
$upload_dir = rtrim((string)($GLOBALS['conf']['upload_dir'] ?? './upload'), '/');
$work_rel_dir = $upload_dir.'/bratonien-webdav-materialize';
$work_root = PHPWG_ROOT_PATH.$work_rel_dir;
if (!is_dir($work_root) && !@mkdir($work_root, 0755, true)) bratonien_tools_webdav_derivative_abort(500, 'WebDAV-Materialisierungsbereich konnte nicht angelegt werden.');
if (!is_writable($work_root)) bratonien_tools_webdav_derivative_abort(500, 'WebDAV-Materialisierungsbereich ist fuer PHP nicht beschreibbar.');

$lock_path = $work_root.'/image-'.$image_id.'.lock';
bratonien_tools_webdav_derivative_debug($request_id, 'waiting_lock', array('lock'=>$lock_path, 'is_link'=>is_link($shadow_path), 'realpath'=>realpath($shadow_path) ?: 'FALSE'));
$lock = @fopen($lock_path, 'c');
if (!$lock || !flock($lock, LOCK_EX))
{
  if ($lock) fclose($lock);
  bratonien_tools_webdav_derivative_abort(503, 'WebDAV-Materialisierungslock konnte nicht gesetzt werden.');
}
bratonien_tools_webdav_derivative_debug($request_id, 'lock_acquired', array('is_link'=>is_link($shadow_path), 'realpath'=>realpath($shadow_path) ?: 'FALSE'));

clearstatcache(true, $target_path);
if (is_file($target_path) && is_readable($target_path))
{
  bratonien_tools_webdav_derivative_debug($request_id, 'target_created_while_waiting', array('target'=>$target_path));
  @flock($lock, LOCK_UN); @fclose($lock);
  if ($redirect_after !== null) { header('Location: '.$redirect_after, true, 302); exit; }
  bratonien_tools_webdav_derivative_serve($target_path);
}

$token = getmypid().'-'.bin2hex(random_bytes(6));
$download_path = $work_root.'/source-'.$image_id.'-'.$token.'.tmp';
$staging_path = $shadow_path.'.bratonien-'.$token.'.tmp';
$placeholder_backup = $shadow_path.'.bratonien-placeholder';
$swapped = false;
$cleaned = false;

$cleanup = function() use (&$cleaned, &$swapped, $shadow_path, $staging_path, $placeholder_backup, $download_path, $lock, $request_id)
{
  if ($cleaned) return;
  $cleaned = true;
  if ($swapped)
  {
    if (is_file($shadow_path) && !is_link($shadow_path)) @unlink($shadow_path);
    if (is_link($placeholder_backup)) @rename($placeholder_backup, $shadow_path);
    $swapped = false;
  }
  if (is_file($staging_path) || is_link($staging_path)) @unlink($staging_path);
  if (is_file($download_path)) @unlink($download_path);
  bratonien_tools_webdav_derivative_debug($request_id, 'restored', array('is_link'=>is_link($shadow_path), 'realpath'=>realpath($shadow_path) ?: 'FALSE'));
  @flock($lock, LOCK_UN);
  @fclose($lock);
};
register_shutdown_function($cleanup);

try
{
  if (is_link($placeholder_backup)) bratonien_tools_webdav_derivative_abort(503, 'WebDAV-Placeholder-Backup existiert bereits; vorheriger Materialisierungslauf ist nicht sauber abgeschlossen.');
  $detail = '';
  $download_start = microtime(true);
  if (!bratonien_tools_webdav_derivative_download($source, $download_path, $detail)) bratonien_tools_webdav_derivative_abort(502, $detail);
  bratonien_tools_webdav_derivative_debug($request_id, 'download_done', array('ms'=>round((microtime(true)-$download_start)*1000, 1), 'bytes'=>@filesize($download_path) ?: 0));

  if (!@copy($download_path, $staging_path)) bratonien_tools_webdav_derivative_abort(500, 'Original konnte nicht in den WebDAV-Shadow-Baum kopiert werden.');
  @chmod($staging_path, 0644);
  if (@getimagesize($staging_path) === false) bratonien_tools_webdav_derivative_abort(500, 'Materialisierte Shadow-Quelle ist kein gueltiges Bild.');

  bratonien_tools_webdav_derivative_debug($request_id, 'swap_begin', array('placeholder'=>$placeholder_target));
  if (!@rename($shadow_path, $placeholder_backup)) bratonien_tools_webdav_derivative_abort(500, 'WebDAV-Placeholder konnte nicht temporaer gesichert werden.');
  if (!@rename($staging_path, $shadow_path))
  {
    @rename($placeholder_backup, $shadow_path);
    bratonien_tools_webdav_derivative_abort(500, 'Materialisiertes Original konnte nicht am Piwigo-Quellpfad aktiviert werden.');
  }
  $swapped = true;
  clearstatcache(true, $shadow_path);
  bratonien_tools_webdav_derivative_debug($request_id, 'swapped', array('is_link'=>is_link($shadow_path), 'realpath'=>realpath($shadow_path) ?: 'FALSE'));
  if (!is_file($shadow_path) || is_link($shadow_path) || @getimagesize($shadow_path) === false) bratonien_tools_webdav_derivative_abort(500, 'Piwigo-Quellpfad enthaelt nach Materialisierung kein gueltiges Original.');

  $generate_start = microtime(true);
  bratonien_tools_webdav_derivative_debug($request_id, 'generate_start', array('target'=>$target_path));
  $generate_ok = bratonien_tools_webdav_derivative_generate($shadow_path, $target_path, $params, $image_row, $detail);
  bratonien_tools_webdav_derivative_debug($request_id, 'generate_done', array('ok'=>$generate_ok, 'ms'=>round((microtime(true)-$generate_start)*1000, 1), 'detail'=>$detail));
  if (!$generate_ok) bratonien_tools_webdav_derivative_abort(500, $detail);

  clearstatcache(true, $target_path);
  $final_size = @getimagesize($target_path);
  if (!is_file($target_path) || !is_readable($target_path) || !is_array($final_size)) bratonien_tools_webdav_derivative_abort(500, 'Piwigo hat kein gueltiges finales Derivat erzeugt.');

  $cleanup();
  bratonien_tools_webdav_derivative_debug($request_id, 'serve', array('target'=>$target_path, 'width'=>$final_size[0] ?? 0, 'height'=>$final_size[1] ?? 0));
  if ($redirect_after !== null) { header('Location: '.$redirect_after, true, 302); exit; }
  bratonien_tools_webdav_derivative_serve($target_path);
}
finally
{
  $cleanup();
}
