<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(BRATONIEN_TOOLS_PATH . 'include/database.class.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/watermark_engine.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/watermark_settings.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/watermark_profiles.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/album_rules.inc.php');

function bratonien_tools_runtime_category_id()
{
  global $page;

  if (!empty($page['category']['id']))
  {
    return (int)$page['category']['id'];
  }

  if (!empty($page['category']) && is_numeric($page['category']))
  {
    return (int)$page['category'];
  }

  return 0;
}

function bratonien_tools_watermark_album_cover_category($src_image, $category_id=null)
{
  static $map = null;

  if ($map === null)
  {
    $map = new SplObjectStorage();
  }

  if (!is_object($src_image))
  {
    return 0;
  }

  if ($category_id !== null)
  {
    $category_id = (int)$category_id;
    if ($category_id > 0)
    {
      $map[$src_image] = $category_id;
    }
    return $category_id;
  }

  return $map->contains($src_image) ? (int)$map[$src_image] : 0;
}

function bratonien_tools_watermark_prepare_album_overview($thumbnails)
{
  if (!is_array($thumbnails))
  {
    return $thumbnails;
  }

  foreach ($thumbnails as &$thumbnail)
  {
    $category_id = (int)($thumbnail['id'] ?? $thumbnail['ID'] ?? 0);
    if ($category_id < 1 || empty($thumbnail['representative']['src_image']) || !is_object($thumbnail['representative']['src_image']))
    {
      continue;
    }

    // Ein Repraesentantenbild kann fuer mehrere Alben verwendet werden. Piwigo
    // reicht in der Albumuebersicht aber nur das SrcImage an get_derivative_url
    // weiter. Deshalb bekommt jedes Album-Cover eine eigene SrcImage-Instanz,
    // damit seine konkrete Albumregel erhalten bleibt.
    $src_image = clone $thumbnail['representative']['src_image'];
    $thumbnail['representative']['src_image'] = $src_image;
    bratonien_tools_watermark_album_cover_category($src_image, $category_id);
  }
  unset($thumbnail);

  return $thumbnails;
}

function bratonien_tools_runtime_derivative_category_id($src_image)
{
  $category_id = bratonien_tools_runtime_category_id();
  if ($category_id > 0)
  {
    return $category_id;
  }

  return bratonien_tools_watermark_album_cover_category($src_image);
}

function bratonien_tools_runtime_effective_rule($category_id)
{
  static $categories = null;
  static $rules = null;
  static $defaults = null;

  if ($categories === null)
  {
    $categories = bratonien_tools_get_category_tree();
    $rules = bratonien_tools_get_album_rules();
    $defaults = bratonien_tools_get_watermark_defaults();
  }

  if ((int)$category_id <= 0)
  {
    if (empty($defaults['public_profile']))
    {
      return array('mode'=>'disabled','profile_id'=>null,'source'=>'global');
    }
    return array('mode'=>'profile','profile_id'=>(int)$defaults['public_profile'],'source'=>'global');
  }

  return bratonien_tools_resolve_album_rule((int)$category_id, $categories, $rules, $defaults);
}

function bratonien_tools_runtime_get_profile($profile_id)
{
  static $profiles = array();
  $profile_id = (int)$profile_id;

  if ($profile_id <= 0)
  {
    return null;
  }

  if (!array_key_exists($profile_id, $profiles))
  {
    $profiles[$profile_id] = bratonien_tools_get_watermark_profile($profile_id) ?: null;
  }

  return $profiles[$profile_id];
}

function bratonien_tools_runtime_sign($rel_url, $profile_id, $profile_version='')
{
  global $conf;
  $key = !empty($conf['secret_key']) ? $conf['secret_key'] : ($conf['db_password'] ?? 'bratonien-tools');
  return hash_hmac('sha256', $profile_id.'|'.$profile_version.'|'.$rel_url, $key);
}

function bratonien_tools_runtime_b64url_encode($value)
{
  return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function bratonien_tools_profile_watermark_path(array $profile)
{
  static $paths = array();

  if (empty($profile['watermark_file']))
  {
    return null;
  }

  $relative = ltrim((string)$profile['watermark_file'], '/');
  if (array_key_exists($relative, $paths))
  {
    return $paths[$relative];
  }

  $allowed_prefix = trim(PWG_LOCAL_DIR, '/').'/watermarks/';
  if (strpos($relative, $allowed_prefix) !== 0)
  {
    $paths[$relative] = null;
    return null;
  }

  $root = realpath(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'watermarks');
  $path = realpath(PHPWG_ROOT_PATH.$relative);
  if (!$root || !$path || strpos($path, $root.DIRECTORY_SEPARATOR) !== 0 || !is_file($path))
  {
    $paths[$relative] = null;
    return null;
  }

  $paths[$relative] = $path;
  return $path;
}

function bratonien_tools_runtime_profile_version(array $profile)
{
  $watermark_path = bratonien_tools_profile_watermark_path($profile);
  $parts = array(
    $profile['id'] ?? 0,
    $profile['watermark_file'] ?? '',
    $profile['scale_percent'] ?? 100,
    $profile['xpos'] ?? 90,
    $profile['ypos'] ?? 90,
    $profile['xrepeat'] ?? 0,
    $profile['yrepeat'] ?? 0,
    $profile['opacity'] ?? 35,
    $profile['min_width'] ?? 10,
    $profile['min_height'] ?? 10,
    $profile['active'] ?? 1,
    $watermark_path ? @filemtime($watermark_path) : 0,
  );

  return substr(sha1(implode('|', $parts)), 0, 16);
}

function bratonien_tools_runtime_canonical_derivative_url($rel_url)
{
  $rel_url = (string)$rel_url;
  if (strpos($rel_url, PWG_DERIVATIVE_DIR) === 0)
  {
    return $rel_url;
  }

  if (preg_match('#^i(?:\\.php)?\\?/(.+)$#', $rel_url, $match) || preg_match('#^i(?:\\.php)?/(.+)$#', $rel_url, $match))
  {
    return PWG_DERIVATIVE_DIR.ltrim(rawurldecode($match[1]), '/');
  }

  return $rel_url;
}

function bratonien_tools_runtime_absolute_url($relative_path)
{
  $relative_path = ltrim((string)$relative_path, '/');

  if (function_exists('get_absolute_root_url'))
  {
    $root = rtrim(get_absolute_root_url(true), '/');
    $plugin_suffix = '/plugins/'.trim(BRATONIEN_TOOLS_ID, '/');

    if (substr($root, -strlen($plugin_suffix)) === $plugin_suffix)
    {
      $root = substr($root, 0, -strlen($plugin_suffix));
    }

    return rtrim($root, '/').'/'.$relative_path;
  }

  return '/'.$relative_path;
}

function bratonien_tools_runtime_cache_descriptor($rel_url, array $profile, $params, $source_path, $extension='')
{
  if (!$params || !$source_path)
  {
    return null;
  }

  $watermark_path = bratonien_tools_profile_watermark_path($profile);
  if (!$watermark_path)
  {
    return null;
  }

  $canonical_rel_url = bratonien_tools_runtime_canonical_derivative_url($rel_url);
  $extension = strtolower((string)$extension);
  if ($extension === '' && preg_match('/\\.(jpe?g|png|gif|webp)(?:$|\\?)/i', $canonical_rel_url, $match))
  {
    $extension = strtolower($match[1]);
  }
  if (!in_array($extension, array('jpg','jpeg','png','gif','webp'), true))
  {
    $extension = 'jpg';
  }

  $profile_id = (int)($profile['id'] ?? 0);
  if ($profile_id <= 0)
  {
    return null;
  }

  $profile_version = bratonien_tools_runtime_profile_version($profile);
  $relative_dir = PWG_DERIVATIVE_DIR.'bratonien-watermark/'.$profile_id;
  $fingerprint = array(
    $canonical_rel_url,
    $profile_version,
    @filemtime($source_path) ?: 0,
  );
  $filename = sha1(implode('|', $fingerprint)).'.'.$extension;
  $relative_path = $relative_dir.'/'.$filename;

  return array(
    'dir' => PHPWG_ROOT_PATH.$relative_dir,
    'path' => PHPWG_ROOT_PATH.$relative_path,
    'relative_path' => $relative_path,
    'url' => bratonien_tools_runtime_absolute_url($relative_path),
    'profile_version' => $profile_version,
    'canonical_rel_url' => $canonical_rel_url,
  );
}

function bratonien_tools_filter_derivative_url($url, $params, $src_image, $rel_url)
{
  if (!bratonien_tools_watermark_engine_enabled())
  {
    return $url;
  }

  $rule = bratonien_tools_runtime_effective_rule(bratonien_tools_runtime_derivative_category_id($src_image));
  if ($rule['mode'] !== 'profile' || empty($rule['profile_id']))
  {
    return $url;
  }

  $profile = bratonien_tools_runtime_get_profile((int)$rule['profile_id']);
  if (!$profile || empty($profile['active']) || !bratonien_tools_profile_watermark_path($profile))
  {
    return $url;
  }

  if (is_object($params) && is_object($src_image) && $src_image->has_size())
  {
    $size = $params->compute_final_size($src_image->get_size());
    if ($size && ($size[0] < (int)$profile['min_width'] || $size[1] < (int)$profile['min_height']))
    {
      return $url;
    }
  }

  if (is_object($params) && is_object($src_image))
  {
    $descriptor = bratonien_tools_runtime_cache_descriptor($rel_url, $profile, $params, $src_image->get_path());
    if ($descriptor && is_file($descriptor['path']) && is_readable($descriptor['path']))
    {
      return $descriptor['url'];
    }
  }

  $profile_id = (int)$profile['id'];
  $profile_version = bratonien_tools_runtime_profile_version($profile);
  $signature = bratonien_tools_runtime_sign($rel_url, $profile_id, $profile_version);
  $endpoint = 'plugins/'.BRATONIEN_TOOLS_ID.'/watermark.php?p='.$profile_id.'&v='.
    rawurlencode($profile_version).'&u='.rawurlencode(bratonien_tools_runtime_b64url_encode($rel_url)).'&s='.$signature;

  return bratonien_tools_runtime_absolute_url($endpoint);
}
