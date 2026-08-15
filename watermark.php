<?php
$piwigo_root = realpath(dirname(__DIR__, 2));
if ($piwigo_root === false)
{
  http_response_code(500);
  exit;
}
define('PHPWG_ROOT_PATH', rtrim($piwigo_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  http_response_code(500);
  exit;
}

require_once(BRATONIEN_TOOLS_PATH.'include/watermark_runtime.inc.php');
require_once(PHPWG_ROOT_PATH.'admin/include/image.class.php');

function bratonien_tools_runtime_b64url_decode($value)
{
  $value = strtr((string)$value, '-_', '+/');
  $padding = strlen($value) % 4;
  if ($padding)
  {
    $value .= str_repeat('=', 4 - $padding);
  }
  return base64_decode($value, true);
}

function bratonien_tools_watermark_fail($code, $message)
{
  http_response_code($code);
  header('Content-Type: text/plain; charset=utf-8');
  echo $message;
  exit;
}

function bratonien_tools_output_image($path)
{
  clearstatcache(true, $path);
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $types = array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp');
  $mtime = @filemtime($path) ?: time();
  $size = @filesize($path) ?: 0;
  $etag = '"'.sha1($path.'|'.$mtime.'|'.$size).'"';

  if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag)
  {
    header('ETag: '.$etag, true, 304);
    header('Cache-Control: public, max-age=31536000, immutable');
    exit;
  }

  header('Content-Type: '.($types[$ext] ?? 'application/octet-stream'));
  if ($size > 0)
  {
    header('Content-Length: '.$size);
  }
  header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');
  header('ETag: '.$etag);
  header('Cache-Control: public, max-age=31536000, immutable');
  readfile($path);
  exit;
}

function bratonien_tools_redirect_to_cache($url)
{
  header('Cache-Control: no-store, max-age=0');
  header('Location: '.$url, true, 302);
  exit;
}

function bratonien_tools_url_to_size($value)
{
  $parts = explode('x', (string)$value, 2);
  if (count($parts) === 1)
  {
    $size = max(1, (int)$parts[0]);
    return array($size, $size);
  }
  return array(max(1, (int)$parts[0]), max(1, (int)$parts[1]));
}

function bratonien_tools_custom_params(array $tokens)
{
  if (empty($tokens))
  {
    return null;
  }

  $token = array_shift($tokens);
  $crop = 0;
  $min_size = null;

  if (isset($token[0]) && $token[0] === 's')
  {
    $size = bratonien_tools_url_to_size(substr($token, 1));
  }
  elseif (isset($token[0]) && $token[0] === 'e')
  {
    $crop = 1;
    $size = $min_size = bratonien_tools_url_to_size(substr($token, 1));
  }
  else
  {
    if (count($tokens) < 2)
    {
      return null;
    }
    $size = bratonien_tools_url_to_size($token);
    $crop_token = array_shift($tokens);
    $min_size = bratonien_tools_url_to_size(array_shift($tokens));
    $crop = function_exists('char_to_fraction') ? char_to_fraction($crop_token) : 0;
  }

  $params = new DerivativeParams(new SizingParams($size, $crop, $min_size));
  ImageStdParams::apply_global($params);
  return $params;
}

function bratonien_tools_parse_derivative($rel_url)
{
  $canonical = bratonien_tools_runtime_canonical_derivative_url($rel_url);
  if (strpos($canonical, PWG_DERIVATIVE_DIR) !== 0)
  {
    return null;
  }

  $location = substr($canonical, strlen(PWG_DERIVATIVE_DIR));
  $location = ltrim(rawurldecode($location), '/');
  if ($location === '' || strpos($location, "\0") !== false || strpos($location, '..') !== false)
  {
    return null;
  }

  $dot = strrpos($location, '.');
  if ($dot === false)
  {
    return null;
  }

  $ext = strtolower(substr($location, $dot + 1));
  if (!in_array($ext, array('jpg','jpeg','png','gif','webp'), true))
  {
    return null;
  }

  $without_ext = substr($location, 0, $dot);
  $dash = strrpos($without_ext, '-');
  if ($dash === false)
  {
    return null;
  }

  $source_rel = substr($without_ext, 0, $dash).'.'.$ext;
  $derivative_token = substr($without_ext, $dash + 1);
  $tokens = explode('_', $derivative_token);
  $type_token = array_shift($tokens);
  $params = null;

  foreach (ImageStdParams::get_defined_type_map() as $type => $candidate)
  {
    if (function_exists('derivative_to_url') && derivative_to_url($type) === $type_token)
    {
      $params = $candidate;
      break;
    }
  }

  if ($params === null && defined('IMG_CUSTOM') && function_exists('derivative_to_url') && derivative_to_url(IMG_CUSTOM) === $type_token)
  {
    $params = bratonien_tools_custom_params($tokens);
  }

  if (!$params)
  {
    return null;
  }

  $source_path = PHPWG_ROOT_PATH.$source_rel;
  if (!is_file($source_path) || !is_readable($source_path))
  {
    return null;
  }

  return array(
    'source_rel'=>$source_rel,
    'source_path'=>$source_path,
    'extension'=>$ext,
    'params'=>$params,
    'canonical_rel'=>$canonical,
    'derivative_path'=>PHPWG_ROOT_PATH.$canonical,
  );
}

function bratonien_tools_source_metadata($source_rel, $source_path)
{
  $rotation = 0;
  $coi = null;
  $escaped = array();
  foreach (array($source_rel, './'.$source_rel) as $candidate)
  {
    $escaped[] = "'".pwg_db_real_escape_string($candidate)."'";
  }

  $query = 'SELECT rotation, coi FROM '.IMAGES_TABLE.' WHERE path IN ('.implode(',', $escaped).') LIMIT 1';
  $row = pwg_db_fetch_assoc(pwg_query($query));
  if ($row)
  {
    $rotation = isset($row['rotation']) ? pwg_image::get_rotation_angle_from_code($row['rotation']) : pwg_image::get_rotation_angle($source_path);
    $coi = $row['coi'] ?? null;
  }

  return array('rotation'=>$rotation,'coi'=>$coi);
}

function bratonien_tools_ensure_piwigo_derivative(array $derivative)
{
  $path = $derivative['derivative_path'];
  $params = $derivative['params'];

  if (is_file($path) && is_readable($path) && @filemtime($path) >= (int)$params->last_mod_time)
  {
    return $path;
  }

  $directory = dirname($path);
  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory))
  {
    return null;
  }

  $metadata = bratonien_tools_source_metadata($derivative['source_rel'], $derivative['source_path']);
  $image = new pwg_image($derivative['source_path']);

  if (!empty($metadata['rotation']))
  {
    $image->rotate($metadata['rotation']);
  }

  $original_size = array($image->get_width(), $image->get_height());
  $crop_rect = null;
  $scaled_size = null;
  $params->sizing->compute($original_size, $metadata['coi'], $crop_rect, $scaled_size);

  if ($crop_rect)
  {
    $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
  }
  if ($scaled_size)
  {
    $image->resize($scaled_size[0], $scaled_size[1]);
  }
  if ($params->sharpen)
  {
    $image->sharpen($params->sharpen);
  }

  $image->write($path);
  $image->destroy();
  @chmod($path, 0644);
  clearstatcache(true, $path);

  return is_file($path) && is_readable($path) ? $path : null;
}

if (!bratonien_tools_watermark_engine_enabled())
{
  bratonien_tools_watermark_fail(404, 'Watermark engine disabled');
}

$profile_id = (int)($_GET['p'] ?? 0);
$profile_version = (string)($_GET['v'] ?? '');
$encoded_url = (string)($_GET['u'] ?? '');
$signature = (string)($_GET['s'] ?? '');
$rel_url = bratonien_tools_runtime_b64url_decode($encoded_url);

if ($profile_id <= 0 || $profile_version === '' || $rel_url === false || $rel_url === '')
{
  bratonien_tools_watermark_fail(400, 'Invalid request');
}

$profile = bratonien_tools_runtime_get_profile($profile_id);
if (!$profile || empty($profile['active']))
{
  bratonien_tools_watermark_fail(404, 'Watermark profile unavailable');
}

$current_profile_version = bratonien_tools_runtime_profile_version($profile);
if (!hash_equals($current_profile_version, $profile_version))
{
  bratonien_tools_watermark_fail(410, 'Watermark profile changed');
}

$expected = bratonien_tools_runtime_sign($rel_url, $profile_id, $profile_version);
if (!hash_equals($expected, $signature))
{
  bratonien_tools_watermark_fail(403, 'Invalid signature');
}

$watermark_path = bratonien_tools_profile_watermark_path($profile);
if (!$watermark_path)
{
  bratonien_tools_watermark_fail(404, 'Watermark file unavailable');
}

$derivative = bratonien_tools_parse_derivative($rel_url);
if (!$derivative)
{
  bratonien_tools_watermark_fail(404, 'Derivative description unavailable');
}

$descriptor = bratonien_tools_runtime_cache_descriptor(
  $rel_url,
  $profile,
  $derivative['params'],
  $derivative['source_path'],
  $derivative['extension']
);
if (!$descriptor)
{
  bratonien_tools_watermark_fail(500, 'Watermark cache description unavailable');
}

if (is_file($descriptor['path']) && is_readable($descriptor['path']))
{
  bratonien_tools_redirect_to_cache($descriptor['url']);
}

if (!is_dir($descriptor['dir']) && !mkdir($descriptor['dir'], 0755, true) && !is_dir($descriptor['dir']))
{
  bratonien_tools_watermark_fail(500, 'Watermark cache directory unavailable');
}

$lock_path = $descriptor['path'].'.lock';
$lock = @fopen($lock_path, 'c');
if (!$lock || !flock($lock, LOCK_EX))
{
  if (is_resource($lock))
  {
    fclose($lock);
  }
  bratonien_tools_watermark_fail(500, 'Watermark cache lock unavailable');
}

if (is_file($descriptor['path']) && is_readable($descriptor['path']))
{
  flock($lock, LOCK_UN);
  fclose($lock);
  bratonien_tools_redirect_to_cache($descriptor['url']);
}

$piwigo_derivative = bratonien_tools_ensure_piwigo_derivative($derivative);
if (!$piwigo_derivative)
{
  flock($lock, LOCK_UN);
  fclose($lock);
  bratonien_tools_watermark_fail(500, 'Piwigo derivative unavailable');
}

$image = new pwg_image($piwigo_derivative);
$width = $image->get_width();
$height = $image->get_height();

if ($width >= (int)$profile['min_width'] && $height >= (int)$profile['min_height'])
{
  $scale_percent = isset($profile['scale_percent']) ? max(1.0, min(1000.0, (float)$profile['scale_percent'])) : 100.0;
  $wm = new pwg_image($watermark_path);
  $original_wm_width = $wm->get_width();
  $original_wm_height = $wm->get_height();
  $wm_width = max(1, (int)round($original_wm_width * $scale_percent / 100));
  $wm_height = max(1, (int)round($original_wm_height * $scale_percent / 100));

  if ($wm_width !== $original_wm_width || $wm_height !== $original_wm_height)
  {
    $wm->resize($wm_width, $wm_height);
  }

  if ($width < $wm_width || $height < $wm_height)
  {
    $fit = min($width / $wm_width, $height / $wm_height);
    $wm_width = max(1, (int)floor($wm_width * $fit));
    $wm_height = max(1, (int)floor($wm_height * $fit));
    $wm->resize($wm_width, $wm_height);
  }

  $x = (int)round(((int)$profile['xpos'] / 100) * ($width - $wm_width));
  $y = (int)round(((int)$profile['ypos'] / 100) * ($height - $wm_height));
  $opacity = (int)$profile['opacity'];
  $image->compose($wm, $x, $y, $opacity);

  $xrepeat = max(0, (int)$profile['xrepeat']);
  $yrepeat = max(0, (int)$profile['yrepeat']);
  if ($xrepeat || $yrepeat)
  {
    $xpad = $wm_width + max(30, (int)round($wm_width / 4));
    $ypad = $wm_height + max(30, (int)round($wm_height / 4));

    for ($i=-$xrepeat; $i<=$xrepeat; $i++)
    {
      for ($j=-$yrepeat; $j<=$yrepeat; $j++)
      {
        if ($i === 0 && $j === 0)
        {
          continue;
        }

        $x2 = $x + $i * $xpad;
        $y2 = $y + $j * $ypad;
        if ($x2 >= 0 && $x2 + $wm_width <= $width && $y2 >= 0 && $y2 + $wm_height <= $height)
        {
          $image->compose($wm, $x2, $y2, $opacity);
        }
      }
    }
  }

  $wm->destroy();
}

$image->write($descriptor['path']);
$image->destroy();
@chmod($descriptor['path'], 0644);
clearstatcache(true, $descriptor['path']);

flock($lock, LOCK_UN);
fclose($lock);

if (!is_file($descriptor['path']) || !is_readable($descriptor['path']))
{
  bratonien_tools_watermark_fail(500, 'Generated watermark cache unavailable');
}

bratonien_tools_redirect_to_cache($descriptor['url']);
