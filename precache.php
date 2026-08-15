<?php
if (PHP_SAPI !== 'cli')
{
  http_response_code(404);
  exit;
}

define('BRATONIEN_TOOLS_PRECACHE_BUILD', true);
define('PHPWG_ROOT_PATH', '../../');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/precache.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-Watermark-Precache/1.1';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  fwrite(STDERR, "Bratonien Tools ist nicht aktiv.\n");
  exit(1);
}

require_once(BRATONIEN_TOOLS_PATH.'include/watermark_runtime.inc.php');
require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');

function bratonien_tools_precache_url_to_size($value)
{
  $parts = explode('x', (string)$value, 2);
  if (count($parts) === 1)
  {
    $size = max(1, (int)$parts[0]);
    return array($size, $size);
  }
  return array(max(1, (int)$parts[0]), max(1, (int)$parts[1]));
}

function bratonien_tools_precache_custom_params($key)
{
  $tokens = explode('_', (string)$key);
  if (!$tokens)
  {
    return null;
  }

  $token = array_shift($tokens);
  $crop = 0;
  $min_size = null;

  if (isset($token[0]) && $token[0] === 's')
  {
    $size = bratonien_tools_precache_url_to_size(substr($token, 1));
  }
  elseif (isset($token[0]) && $token[0] === 'e')
  {
    $crop = 1;
    $size = $min_size = bratonien_tools_precache_url_to_size(substr($token, 1));
  }
  else
  {
    if (count($tokens) < 2)
    {
      return null;
    }
    $size = bratonien_tools_precache_url_to_size($token);
    $crop_token = array_shift($tokens);
    $min_size = bratonien_tools_precache_url_to_size(array_shift($tokens));
    $crop = char_to_fraction($crop_token);
  }

  $params = new DerivativeParams(new SizingParams($size, $crop, $min_size));
  ImageStdParams::apply_global($params);
  return $params;
}

if (!bratonien_tools_watermark_engine_enabled())
{
  echo "Wasserzeichen-Engine ist deaktiviert; kein Precache notwendig.\n";
  exit(0);
}

$profiles = array();
foreach (bratonien_tools_get_watermark_profiles() as $profile)
{
  $profile_id = (int)$profile['id'];
  if ($profile_id > 0 && !empty($profile['active']) && bratonien_tools_profile_watermark_path($profile))
  {
    $profiles[$profile_id] = $profile;
  }
}

if (!$profiles)
{
  echo "Keine aktiven Wasserzeichenprofile vorhanden.\n";
  exit(0);
}

$categories = bratonien_tools_get_category_tree();
$rules = bratonien_tools_get_album_rules();
$defaults = bratonien_tools_get_watermark_defaults();
$category_profiles = array();
foreach ($categories as $category)
{
  $category_id = (int)$category['id'];
  $effective = bratonien_tools_resolve_album_rule($category_id, $categories, $rules, $defaults);
  if ($effective['mode'] === 'profile' && !empty($effective['profile_id']))
  {
    $profile_id = (int)$effective['profile_id'];
    if (isset($profiles[$profile_id]))
    {
      $category_profiles[$category_id] = $profile_id;
    }
  }
}

$image_categories = array();
$result = pwg_query('SELECT image_id, category_id FROM '.IMAGE_CATEGORY_TABLE.' ORDER BY image_id, category_id');
while ($row = pwg_db_fetch_assoc($result))
{
  $image_id = (int)$row['image_id'];
  $image_categories[$image_id][] = (int)$row['category_id'];
}

$variants = array();
foreach (ImageStdParams::get_defined_type_map() as $type => $params)
{
  $variants[$type] = $params;
}
foreach (ImageStdParams::$custom as $custom_key => $last_used)
{
  $params = bratonien_tools_precache_custom_params($custom_key);
  if ($params)
  {
    $variants['custom:'.$custom_key] = $params;
  }
}

$endpoint = 'http://127.0.0.1/plugins/'.BRATONIEN_TOOLS_ID.'/watermark.php';
$checked = 0;
$generated = 0;
$already_cached = 0;
$skipped = 0;
$errors = 0;

$result = pwg_query('SELECT * FROM '.IMAGES_TABLE.' ORDER BY id');
while ($image = pwg_db_fetch_assoc($result))
{
  $image_id = (int)$image['id'];
  $profile_ids = array();

  foreach ($image_categories[$image_id] ?? array() as $category_id)
  {
    if (isset($category_profiles[$category_id]))
    {
      $profile_ids[$category_profiles[$category_id]] = true;
    }
  }

  if (!$profile_ids)
  {
    $skipped++;
    continue;
  }

  $src_image = new SrcImage($image);
  $source_path = $src_image->get_path();
  if (!is_file($source_path) || !is_readable($source_path))
  {
    fwrite(STDERR, "Bild #$image_id: Quelldatei nicht lesbar: $source_path\n");
    $errors++;
    continue;
  }

  foreach (array_keys($profile_ids) as $profile_id)
  {
    $profile = $profiles[$profile_id];

    foreach ($variants as $variant_name => $params)
    {
      $checked++;

      if ($src_image->has_size())
      {
        $size = $params->compute_final_size($src_image->get_size());
        if ($size && ($size[0] < (int)$profile['min_width'] || $size[1] < (int)$profile['min_height']))
        {
          $skipped++;
          continue;
        }
      }

      $derivative = new DerivativeImage($params, $src_image);
      $derivative_path = $derivative->get_path();
      $root_path = PHPWG_ROOT_PATH;
      if (strpos($derivative_path, $root_path) !== 0)
      {
        $skipped++;
        continue;
      }

      $rel_url = substr($derivative_path, strlen($root_path));
      if (strpos($rel_url, PWG_DERIVATIVE_DIR) !== 0)
      {
        $skipped++;
        continue;
      }

      $extension = strtolower(pathinfo($derivative_path, PATHINFO_EXTENSION));
      $descriptor = bratonien_tools_runtime_cache_descriptor($rel_url, $profile, $params, $source_path, $extension);
      if (!$descriptor)
      {
        $errors++;
        continue;
      }

      if (is_file($descriptor['path']) && is_readable($descriptor['path']))
      {
        $already_cached++;
        continue;
      }

      $profile_version = $descriptor['profile_version'];
      $signature = bratonien_tools_runtime_sign($rel_url, $profile_id, $profile_version);
      $url = $endpoint.'?p='.$profile_id.'&v='.rawurlencode($profile_version).
        '&u='.rawurlencode(bratonien_tools_runtime_b64url_encode($rel_url)).'&s='.$signature;

      $ok = false;
      if (function_exists('curl_init'))
      {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
          CURLOPT_RETURNTRANSFER => false,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_MAXREDIRS => 2,
          CURLOPT_CONNECTTIMEOUT => 3,
          CURLOPT_TIMEOUT => 120,
          CURLOPT_FAILONERROR => false,
          CURLOPT_USERAGENT => 'Bratonien-Watermark-Precache/1.1',
          CURLOPT_WRITEFUNCTION => static function ($ch, $data) { return strlen($data); },
        ));
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        $ok = ($status >= 200 && $status < 300 && is_file($descriptor['path']));
        if (!$ok)
        {
          fwrite(STDERR, "Bild #$image_id / Profil #$profile_id / $variant_name: Precache fehlgeschlagen (HTTP $status".($curl_error !== '' ? ", $curl_error" : '').").\n");
        }
      }
      else
      {
        $context = stream_context_create(array('http'=>array('timeout'=>120, 'ignore_errors'=>true)));
        @file_get_contents($url, false, $context);
        $ok = is_file($descriptor['path']);
        if (!$ok)
        {
          fwrite(STDERR, "Bild #$image_id / Profil #$profile_id / $variant_name: Precache fehlgeschlagen.\n");
        }
      }

      if ($ok)
      {
        $generated++;
      }
      else
      {
        $errors++;
      }
    }
  }
}

printf(
  "Wasserzeichen-Precache: %d Varianten geprueft, %d neu erzeugt, %d bereits vorhanden, %d uebersprungen, %d Fehler.\n",
  $checked,
  $generated,
  $already_cached,
  $skipped,
  $errors
);

exit($errors > 0 ? 1 : 0);
