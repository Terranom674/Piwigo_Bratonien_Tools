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

function bratonien_tools_webdav_derivative_connection(array $source, &$detail=null)
{
  $detail = '';
  $table = $GLOBALS['prefixeTable'].'bratonien_tools_nc_connections';
  $result = pwg_query('SELECT config_json, secret_blob FROM `'.$table.'` WHERE id='.(int)$source['connection_id'].' LIMIT 1');
  if (!pwg_db_num_rows($result)) { $detail = 'WebDAV-Verbindung nicht gefunden.'; return null; }

  $row = pwg_db_fetch_assoc($result);
  $config = json_decode((string)$row['config_json'], true);
  if (!is_array($config)) { $detail = 'WebDAV-Konfiguration ist ungueltig.'; return null; }

  $key_result = pwg_query("SELECT value FROM ".$GLOBALS['prefixeTable']."config WHERE param='bratonien_nc_connector_secret' LIMIT 1");
  if (!pwg_db_num_rows($key_result)) { $detail = 'Connector-Schluessel fehlt.'; return null; }
  $key_row = pwg_db_fetch_assoc($key_result);
  $credentials = bratonien_tools_webdav_derivative_decrypt_secret((string)$row['secret_blob'], (string)$key_row['value']);
  if (!is_array($credentials)) { $detail = 'WebDAV-Zugangsdaten konnten nicht gelesen werden.'; return null; }

  $base_url = rtrim((string)($config['nextcloud_url'] ?? ''), '/');
  $user = trim((string)($credentials['nextcloud_user'] ?? ''));
  $password = (string)($credentials['nextcloud_password'] ?? '');
  if ($base_url === '' || $user === '' || $password === '') { $detail = 'WebDAV-Verbindung ist unvollstaendig.'; return null; }

  return array('base_url'=>$base_url, 'user'=>$user, 'password'=>$password);
}

function bratonien_tools_webdav_derivative_download_url($url, array $connection, $destination, &$detail=null)
{
  $detail = '';
  $fp = @fopen($destination, 'xb');
  if (!$fp) { $detail = 'Temporaere Quelldatei konnte nicht angelegt werden.'; return false; }

  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $connection['user'].':'.$connection['password'],
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

function bratonien_tools_webdav_derivative_download(array $source, array $connection, $destination, &$detail=null)
{
  $webdav_path = trim((string)($source['webdav_path'] ?? ''), '/');
  if ($webdav_path === '') { $detail = 'WebDAV-Bildquelle ist unvollstaendig.'; return false; }

  $url = $connection['base_url'].'/remote.php/dav/files/'.rawurlencode($connection['user']).'/'.bratonien_tools_webdav_derivative_quote_path($webdav_path);
  return bratonien_tools_webdav_derivative_download_url($url, $connection, $destination, $detail);
}

function bratonien_tools_webdav_derivative_preview_plan($variant, array $derivative_size, array $source, array $image_row)
{
  if ($variant !== 'custom:s9999x250' && $variant !== 'standard:square') return null;
  if ((int)($source['fileid'] ?? 0) < 1) return null;
  if ((int)($source['width'] ?? 0) < 1 || (int)($source['height'] ?? 0) < 1) return null;
  if ((int)($image_row['rotation'] ?? 0) !== 0) return null;

  $target_width = (int)($derivative_size[0] ?? 0);
  $target_height = (int)($derivative_size[1] ?? 0);
  if ($target_width < 1 || $target_height < 1 || max($target_width, $target_height) > 800) return null;

  $source_width = (int)$source['width'];
  $source_height = (int)$source['height'];
  $required_width = $target_width * 2;
  $required_height = $target_height * 2;
  $scale = max($required_width / $source_width, $required_height / $source_height);
  $preview_width = max(32, (int)ceil($source_width * $scale));
  $preview_height = max(32, (int)ceil($source_height * $scale));

  if (max($preview_width, $preview_height) > 1600) return null;

  return array(
    'fileid'=>(int)$source['fileid'],
    'width'=>$preview_width,
    'height'=>$preview_height,
  );
}

function bratonien_tools_webdav_derivative_download_preview(array $plan, array $connection, $destination, &$detail=null)
{
  $query = http_build_query(array(
    'fileId'=>(int)$plan['fileid'],
    'x'=>(int)$plan['width'],
    'y'=>(int)$plan['height'],
    'a'=>'true',
    'forceIcon'=>'false',
    'mode'=>'fill',
    'mimeFallback'=>'false',
  ), '', '&', PHP_QUERY_RFC3986);
  $url = $connection['base_url'].'/index.php/core/preview?'.$query;
  return bratonien_tools_webdav_derivative_download_url($url, $connection, $destination, $detail);
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
  if (!is_file($source_path) || !is_readable($source_path)) { $detail = 'Materialisierte Bildquelle ist nicht lesbar.'; return false; }
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

$request_id = substr(bin2hex(random_bytes(8)), 0, 8);
$image_id = (int)($_GET['id'] ?? 0);
$variant = trim((string)($_GET['variant'] ?? ''));
$after_url = '';

if ($image_id < 1 || $variant === '') bratonien_tools_webdav_derivative_abort(400, 'Ungueltige Derivatanforderung.');

bratonien_tools_webdav_derivative_debug($request_id, 'start', array('image_id'=>$image_id, 'variant'=>$variant));

if (!empty($_GET['after']))
{
  $raw_after = strtr((string)$_GET['after'], '-_', '+/');
  $padding = strlen($raw_after) % 4;
  if ($padding) $raw_after .= str_repeat('=', 4 - $padding);
  $decoded_after = base64_decode($raw_after, true);
  if (is_string($decoded_after)) $after_url = $decoded_after;
}
if ($after_url !== '')
{
  $expected = bratonien_tools_webdav_materialize_after_signature($image_id, $variant, $after_url);
  if (!hash_equals($expected, (string)($_GET['sig'] ?? ''))) bratonien_tools_webdav_derivative_abort(403, 'Ungueltige Derivatsignatur.');
}

$result = pwg_query('SELECT id, path, width, height, rotation, coi FROM '.IMAGES_TABLE.' WHERE id='.$image_id.' LIMIT 1');
if (!pwg_db_num_rows($result)) bratonien_tools_webdav_derivative_abort(404, 'Bild nicht gefunden.');
$image_row = pwg_db_fetch_assoc($result);

if (function_exists('get_sql_condition_FandF'))
{
  $forbidden = get_sql_condition_FandF(array('visible_images'=>'id'), ' AND');
  if ($forbidden !== '')
  {
    $visible = pwg_query('SELECT id FROM '.IMAGES_TABLE.' WHERE id='.$image_id.$forbidden.' LIMIT 1');
    if (!pwg_db_num_rows($visible)) bratonien_tools_webdav_derivative_abort(403, 'Kein Zugriff auf das Bild.');
  }
}

$source = bratonien_tools_webdav_materialize_source_info($image_id);
if (!$source) bratonien_tools_webdav_derivative_abort(404, 'WebDAV-Bildquelle nicht gefunden.');
bratonien_tools_webdav_derivative_debug($request_id, 'source_resolved', array(
  'connection_id'=>$source['connection_id'],
  'root_fileid'=>$source['root_fileid'],
  'fileid'=>$source['fileid'] ?? 0,
  'webdav_path'=>$source['webdav_path'],
));

$params = bratonien_tools_webdav_materialize_params_from_variant($variant);
if (!$params) bratonien_tools_webdav_derivative_abort(400, 'Unbekannte Derivatvariante.');

$src_image = new SrcImage($image_row);
$real_derivative = new DerivativeImage($params, $src_image);
$target_path = $real_derivative->get_path();
$derivative_size = $real_derivative->get_size();

if ($target_path !== '' && is_file($target_path) && is_readable($target_path))
{
  bratonien_tools_webdav_derivative_debug($request_id, 'target_exists', array('target'=>$target_path));
  header('Content-Type: '.(function_exists('get_mimetype') ? get_mimetype($target_path) : 'image/jpeg'));
  readfile($target_path);
  exit;
}

$source_logical_path = (string)$image_row['path'];
if (strpos($source_logical_path, '/') !== 0)
{
  $source_logical_path = PHPWG_ROOT_PATH.ltrim(preg_replace('#^\./#', '', $source_logical_path), '/');
}

$lock_dir = PHPWG_ROOT_PATH.'upload/bratonien-webdav-materialize';
if (!is_dir($lock_dir)) @mkdir($lock_dir, 0755, true);
$lock_path = $lock_dir.'/image-'.$image_id.'.lock';
$lock_handle = fopen($lock_path, 'c');
if (!$lock_handle) bratonien_tools_webdav_derivative_abort(500, 'Derivat-Lock konnte nicht geoeffnet werden.');
bratonien_tools_webdav_derivative_debug($request_id, 'waiting_lock', array('lock'=>$lock_path, 'is_link'=>is_link($source_logical_path)));
flock($lock_handle, LOCK_EX);
bratonien_tools_webdav_derivative_debug($request_id, 'lock_acquired', array('is_link'=>is_link($source_logical_path)));

clearstatcache(true, $target_path);
if ($target_path !== '' && is_file($target_path) && is_readable($target_path))
{
  bratonien_tools_webdav_derivative_debug($request_id, 'target_created_while_waiting', array('target'=>$target_path));
  flock($lock_handle, LOCK_UN);
  fclose($lock_handle);
  header('Content-Type: '.(function_exists('get_mimetype') ? get_mimetype($target_path) : 'image/jpeg'));
  readfile($target_path);
  exit;
}

$detail = '';
$connection = bratonien_tools_webdav_derivative_connection($source, $detail);
if (!$connection)
{
  flock($lock_handle, LOCK_UN);
  fclose($lock_handle);
  bratonien_tools_webdav_derivative_abort(502, $detail !== '' ? $detail : 'WebDAV-Verbindung konnte nicht geladen werden.');
}

$temp_dir = $lock_dir.'/tmp';
if (!is_dir($temp_dir)) @mkdir($temp_dir, 0755, true);
$temp_source = $temp_dir.'/source-'.$image_id.'-'.$request_id.'.img';
$source_mode = 'original';
$preview_plan = bratonien_tools_webdav_derivative_preview_plan($variant, $derivative_size, $source, $image_row);

if ($preview_plan)
{
  bratonien_tools_webdav_derivative_debug($request_id, 'preview_start', array(
    'fileid'=>$preview_plan['fileid'],
    'width'=>$preview_plan['width'],
    'height'=>$preview_plan['height'],
  ));
  $download_start = microtime(true);
  if (bratonien_tools_webdav_derivative_download_preview($preview_plan, $connection, $temp_source, $detail))
  {
    $source_mode = 'preview';
    bratonien_tools_webdav_derivative_debug($request_id, 'download_done', array(
      'mode'=>$source_mode,
      'ms'=>round((microtime(true) - $download_start) * 1000, 1),
      'bytes'=>filesize($temp_source),
    ));
  }
  else
  {
    bratonien_tools_webdav_derivative_debug($request_id, 'preview_failed', array('detail'=>$detail));
    $detail = '';
  }
}

if ($source_mode !== 'preview')
{
  @unlink($temp_source);
  $download_start = microtime(true);
  if (!bratonien_tools_webdav_derivative_download($source, $connection, $temp_source, $detail))
  {
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);
    bratonien_tools_webdav_derivative_abort(502, $detail !== '' ? $detail : 'WebDAV-Original konnte nicht geladen werden.');
  }
  bratonien_tools_webdav_derivative_debug($request_id, 'download_done', array(
    'mode'=>'original',
    'ms'=>round((microtime(true) - $download_start) * 1000, 1),
    'bytes'=>filesize($temp_source),
  ));
}

$source_path = realpath($source_logical_path);
if ($source_path === false || !is_file($source_path))
{
  @unlink($temp_source);
  flock($lock_handle, LOCK_UN);
  fclose($lock_handle);
  bratonien_tools_webdav_derivative_abort(500, 'Physischer Platzhalter konnte nicht aufgeloest werden.');
}

$placeholder_backup = $source_path.'.bratonien-placeholder-'.$request_id;
$staging_path = $source_logical_path.'.bratonien-source-'.$request_id;
@unlink($placeholder_backup);
@unlink($staging_path);
if (!@copy($temp_source, $staging_path))
{
  @unlink($temp_source);
  flock($lock_handle, LOCK_UN);
  fclose($lock_handle);
  bratonien_tools_webdav_derivative_abort(500, 'Temporaere Bildquelle konnte nicht bereitgestellt werden.');
}

$swapped = false;
try
{
  bratonien_tools_webdav_derivative_debug($request_id, 'swap_begin', array('placeholder'=>$source_path, 'mode'=>$source_mode));
  if (!@rename($source_path, $placeholder_backup)) throw new RuntimeException('Platzhalter konnte nicht gesichert werden.');
  if (!@rename($staging_path, $source_path))
  {
    @rename($placeholder_backup, $source_path);
    throw new RuntimeException('Temporaere Bildquelle konnte nicht eingesetzt werden.');
  }
  $swapped = true;
  bratonien_tools_webdav_derivative_debug($request_id, 'swapped', array('is_link'=>is_link($source_logical_path), 'mode'=>$source_mode));

  $generate_start = microtime(true);
  bratonien_tools_webdav_derivative_debug($request_id, 'generate_start', array('target'=>$target_path, 'mode'=>$source_mode));
  $ok = bratonien_tools_webdav_derivative_generate($source_path, $target_path, $params, $image_row, $detail);
  bratonien_tools_webdav_derivative_debug($request_id, 'generate_done', array(
    'ok'=>$ok,
    'mode'=>$source_mode,
    'ms'=>round((microtime(true) - $generate_start) * 1000, 1),
    'detail'=>$detail,
  ));
  if (!$ok) throw new RuntimeException($detail !== '' ? $detail : 'Piwigo-Derivat konnte nicht erzeugt werden.');
}
catch (Throwable $e)
{
  if ($swapped)
  {
    @unlink($source_path);
    @rename($placeholder_backup, $source_path);
  }
  else
  {
    @unlink($staging_path);
  }
  @unlink($temp_source);
  flock($lock_handle, LOCK_UN);
  fclose($lock_handle);
  bratonien_tools_webdav_derivative_abort(500, $e->getMessage());
}

@unlink($source_path);
@rename($placeholder_backup, $source_path);
@unlink($temp_source);
bratonien_tools_webdav_derivative_debug($request_id, 'restored', array('is_link'=>is_link($source_logical_path)));

flock($lock_handle, LOCK_UN);
fclose($lock_handle);

if (!is_file($target_path) || !is_readable($target_path)) bratonien_tools_webdav_derivative_abort(500, 'Erzeugtes Derivat fehlt.');
$final_size = @getimagesize($target_path);
bratonien_tools_webdav_derivative_debug($request_id, 'serve', array(
  'target'=>$target_path,
  'width'=>(int)($final_size[0] ?? 0),
  'height'=>(int)($final_size[1] ?? 0),
  'mode'=>$source_mode,
));
header('Content-Type: '.(function_exists('get_mimetype') ? get_mimetype($target_path) : 'image/jpeg'));
readfile($target_path);
