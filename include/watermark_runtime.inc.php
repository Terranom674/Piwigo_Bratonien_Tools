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
  if (empty($profile['watermark_file']))
  {
    return null;
  }

  $relative = ltrim((string)$profile['watermark_file'], '/');
  $allowed_prefix = trim(PWG_LOCAL_DIR, '/').'/watermarks/';
  if (strpos($relative, $allowed_prefix) !== 0)
  {
    return null;
  }

  $root = realpath(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'watermarks');
  $path = realpath(PHPWG_ROOT_PATH.$relative);
  if (!$root || !$path || strpos($path, $root.DIRECTORY_SEPARATOR) !== 0 || !is_file($path))
  {
    return null;
  }

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

function bratonien_tools_filter_derivative_url($url, $params, $src_image, $rel_url)
{
  if (!bratonien_tools_watermark_engine_enabled())
  {
    return $url;
  }

  $rule = bratonien_tools_runtime_effective_rule(bratonien_tools_runtime_category_id());
  if ($rule['mode'] !== 'profile' || empty($rule['profile_id']))
  {
    return $url;
  }

  $profile = bratonien_tools_get_watermark_profile((int)$rule['profile_id']);
  if (!$profile || empty($profile['active']))
  {
    return $url;
  }

  if (!bratonien_tools_profile_watermark_path($profile))
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

  $profile_id = (int)$profile['id'];
  $profile_version = bratonien_tools_runtime_profile_version($profile);
  $signature = bratonien_tools_runtime_sign($rel_url, $profile_id, $profile_version);

  return get_root_url().'plugins/'.BRATONIEN_TOOLS_ID.'/watermark.php?p='.$profile_id.'&v='.
    rawurlencode($profile_version).'&u='.rawurlencode(bratonien_tools_runtime_b64url_encode($rel_url)).'&s='.$signature;
}
