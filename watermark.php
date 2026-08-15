<?php
define('PHPWG_ROOT_PATH', '../../');
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

function bratonien_tools_derivative_physical_path($rel_url)
{
  if (strpos($rel_url, "\0") !== false || strpos($rel_url, '..') !== false)
  {
    return null;
  }

  if (strpos($rel_url, PWG_DERIVATIVE_DIR) === 0)
  {
    return PHPWG_ROOT_PATH.$rel_url;
  }

  if (preg_match('#^i(?:\.php)?\?/(.+)$#', $rel_url, $m))
  {
    return PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR.$m[1];
  }

  if (preg_match('#^i(?:\.php)?/(.+)$#', $rel_url, $m))
  {
    return PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR.$m[1];
  }

  return null;
}

function bratonien_tools_fetch_derivative($rel_url, $physical_path)
{
  if ($physical_path && is_file($physical_path) && is_readable($physical_path))
  {
    return array('path'=>$physical_path, 'temporary'=>false);
  }

  $url = get_absolute_root_url().ltrim($rel_url, '/');
  $data = false;

  if (function_exists('curl_init'))
  {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 3,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_FAILONERROR => true,
      CURLOPT_USERAGENT => 'BratonienTools/0.2',
    ));
    $data = curl_exec($ch);
    curl_close($ch);
  }
  elseif (ini_get('allow_url_fopen'))
  {
    $context = stream_context_create(array('http'=>array('timeout'=>30, 'follow_location'=>1)));
    $data = @file_get_contents($url, false, $context);
  }

  if ($data === false || $data === '')
  {
    return null;
  }

  if ($physical_path && is_file($physical_path) && is_readable($physical_path))
  {
    return array('path'=>$physical_path, 'temporary'=>false);
  }

  $ext = 'jpg';
  if (preg_match('/\.(jpe?g|png|gif|webp)(?:$|\?)/i', $rel_url, $m))
  {
    $ext = strtolower($m[1]);
  }

  $tmp = tempnam(sys_get_temp_dir(), 'btwm_');
  $tmp_with_ext = $tmp.'.'.$ext;
  @rename($tmp, $tmp_with_ext);
  file_put_contents($tmp_with_ext, $data);

  return array('path'=>$tmp_with_ext, 'temporary'=>true);
}

function bratonien_tools_output_image($path, $delete_after=false)
{
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $types = array(
    'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png',
    'gif'=>'image/gif', 'webp'=>'image/webp',
  );

  header('Content-Type: '.($types[$ext] ?? 'application/octet-stream'));
  header('Content-Length: '.filesize($path));
  header('Cache-Control: public, max-age=604800');
  readfile($path);

  if ($delete_after)
  {
    @unlink($path);
  }
  exit;
}

if (!bratonien_tools_watermark_engine_enabled())
{
  bratonien_tools_watermark_fail(404, 'Watermark engine disabled');
}

$profile_id = (int)($_GET['p'] ?? 0);
$encoded_url = (string)($_GET['u'] ?? '');
$signature = (string)($_GET['s'] ?? '');
$rel_url = bratonien_tools_runtime_b64url_decode($encoded_url);

if ($profile_id <= 0 || $rel_url === false || $rel_url === '')
{
  bratonien_tools_watermark_fail(400, 'Invalid request');
}

$expected = bratonien_tools_runtime_sign($rel_url, $profile_id);
if (!hash_equals($expected, $signature))
{
  bratonien_tools_watermark_fail(403, 'Invalid signature');
}

$profile = bratonien_tools_get_watermark_profile($profile_id);
if (!$profile || empty($profile['active']) || empty($profile['watermark_file']))
{
  bratonien_tools_watermark_fail(404, 'Watermark profile unavailable');
}

$watermark_root = realpath(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'watermarks');
$watermark_path = realpath(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'watermarks/'.$profile['watermark_file']);
if (!$watermark_root || !$watermark_path || strpos($watermark_path, $watermark_root.DIRECTORY_SEPARATOR) !== 0 || !is_file($watermark_path))
{
  bratonien_tools_watermark_fail(404, 'Watermark file unavailable');
}

$physical_path = bratonien_tools_derivative_physical_path($rel_url);
$source = bratonien_tools_fetch_derivative($rel_url, $physical_path);
if (!$source)
{
  bratonien_tools_watermark_fail(502, 'Derivative could not be generated');
}

$ext = strtolower(pathinfo($source['path'], PATHINFO_EXTENSION));
if (!in_array($ext, array('jpg','jpeg','png','gif','webp'), true))
{
  $ext = 'jpg';
}

$cache_dir = PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR.'bratonien-watermark/'.$profile_id;
if (!is_dir($cache_dir) && !mkdir($cache_dir, 0755, true) && !is_dir($cache_dir))
{
  if ($source['temporary']) @unlink($source['path']);
  bratonien_tools_watermark_fail(500, 'Cache directory unavailable');
}

$cache_fingerprint = array(
  $rel_url,
  $profile['watermark_file'], $profile['xpos'], $profile['ypos'],
  $profile['xrepeat'], $profile['yrepeat'], $profile['opacity'],
  $profile['min_width'], $profile['min_height'], $profile['active'],
  @filemtime($watermark_path),
);
$cache_path = $cache_dir.'/'.sha1(implode('|', $cache_fingerprint)).'.'.$ext;

if (is_file($cache_path))
{
  if ($source['temporary']) @unlink($source['path']);
  bratonien_tools_output_image($cache_path);
}

$image = new pwg_image($source['path']);
$width = $image->get_width();
$height = $image->get_height();

if ($width < (int)$profile['min_width'] || $height < (int)$profile['min_height'])
{
  $image->destroy();
  bratonien_tools_output_image($source['path'], $source['temporary']);
}

$wm = new pwg_image($watermark_path);
$wm_width = $wm->get_width();
$wm_height = $wm->get_height();

if ($width < $wm_width || $height < $wm_height)
{
  $scale = min($width / $wm_width, $height / $wm_height);
  $wm_width = max(1, (int)floor($wm_width * $scale));
  $wm_height = max(1, (int)floor($wm_height * $scale));
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
$image->write($cache_path);
$image->destroy();
@chmod($cache_path, 0644);

if ($source['temporary'])
{
  @unlink($source['path']);
}

bratonien_tools_output_image($cache_path);
