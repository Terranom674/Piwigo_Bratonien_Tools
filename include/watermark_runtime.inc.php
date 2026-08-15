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

function bratonien_tools_runtime_sign($rel_url, $profile_id)
{
  global $conf;
  $key = !empty($conf['secret_key']) ? $conf['secret_key'] : $conf['db_password'];
  return hash_hmac('sha256', $profile_id.'|'.$rel_url, $key);
}

function bratonien_tools_runtime_b64url_encode($value)
{
  return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
  if (!$profile || empty($profile['watermark_file']))
  {
    return $url;
  }

  $watermark_path = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'watermarks/'.$profile['watermark_file'];
  if (!is_file($watermark_path))
  {
    return $url;
  }

  $profile_id = (int)$profile['id'];
  $signature = bratonien_tools_runtime_sign($rel_url, $profile_id);

  return get_root_url().'plugins/'.BRATONIEN_TOOLS_ID.'/watermark.php?p='.$profile_id.'&u='.
    rawurlencode(bratonien_tools_runtime_b64url_encode($rel_url)).'&s='.$signature;
}
