<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

check_status(ACCESS_ADMINISTRATOR);

require_once(BRATONIEN_TOOLS_PATH . 'include/tool_registry.inc.php');

$tools = bratonien_tools_get_tools();
$messages = array();
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bratonien_tool']))
{
  check_pwg_token();
  $tool_id = (string)$_POST['bratonien_tool'];

  if (!isset($tools[$tool_id]))
  {
    $errors[] = 'Unbekanntes Werkzeug.';
  }
  elseif (!empty($tools[$tool_id]['handler']) && is_callable($tools[$tool_id]['handler']))
  {
    try
    {
      $result = call_user_func($tools[$tool_id]['handler']);
      if (!empty($result['message']))
      {
        $messages[] = $result['message'];
      }

      if (in_array($tool_id, array(
        'watermark_save',
        'watermark_engine',
        'watermark_profile_save',
        'watermark_profile_delete',
        'watermark_profile_duplicate',
        'watermark_defaults',
        'watermark_rule',
      ), true))
      {
        bratonien_tools_request_watermark_precache();
        $messages[] = 'Wasserzeichen-Precache wurde vorgemerkt.';
      }
    }
    catch (Throwable $e)
    {
      $errors[] = $e->getMessage();
    }
  }
}

$watermark = bratonien_tools_get_watermark_data();
$profiles = bratonien_tools_get_watermark_profiles();
$defaults = bratonien_tools_get_watermark_defaults();
$categories = bratonien_tools_get_category_tree();
$rules = bratonien_tools_get_album_rules();
$engine = bratonien_tools_get_watermark_engine_config();

$watermark_options = array();
$watermark_meta = array();
foreach ($watermark['files'] as $file => $name)
{
  $absolute = PHPWG_ROOT_PATH.ltrim($file, '/');
  $size = is_file($absolute) ? @getimagesize($absolute) : false;
  $option = array(
    'file' => $file,
    'name' => $name,
    'width' => $size ? (int)$size[0] : 0,
    'height' => $size ? (int)$size[1] : 0,
    'url' => get_root_url().ltrim($file, '/'),
  );
  $watermark_options[] = $option;
  $watermark_meta[$file] = $option;
}

$current_watermark_meta = isset($watermark_meta[$watermark['file']])
  ? $watermark_meta[$watermark['file']]
  : array('width'=>0, 'height'=>0, 'url'=>'');
$watermark['original_width'] = (int)$current_watermark_meta['width'];
$watermark['original_height'] = (int)$current_watermark_meta['height'];
$watermark['preview_url'] = (string)$current_watermark_meta['url'];
$watermark['scale_percent'] = isset($watermark['scale_percent']) ? (float)$watermark['scale_percent'] : 100.0;

foreach ($profiles as &$profile)
{
  $file = (string)($profile['watermark_file'] ?? '');
  $meta = isset($watermark_meta[$file]) ? $watermark_meta[$file] : array('width'=>0,'height'=>0,'url'=>'');
  $profile['original_width'] = (int)$meta['width'];
  $profile['original_height'] = (int)$meta['height'];
  $profile['preview_url'] = (string)$meta['url'];
  $profile['scale_percent'] = isset($profile['scale_percent']) ? (float)$profile['scale_percent'] : 100.0;
}
unset($profile);

$profile_names = array();
foreach ($profiles as $profile)
{
  $profile_names[(int)$profile['id']] = $profile['name'];
}

foreach ($categories as &$category)
{
  $id = (int)$category['id'];
  $category['rule'] = $rules[$id] ?? array('mode'=>'inherit','profile_id'=>null);
  $category['effective'] = bratonien_tools_resolve_album_rule($id, $categories, $rules, $defaults);

  if ($category['effective']['mode'] === 'disabled')
  {
    $category['effective_label'] = 'Kein Wasserzeichen';
  }
  else
  {
    $pid = (int)$category['effective']['profile_id'];
    $category['effective_label'] = $profile_names[$pid] ?? ('Profil #'.$pid);
  }
}
unset($category);

$template->assign(array(
  'BRATONIEN_MESSAGES' => $messages,
  'BRATONIEN_ERRORS' => $errors,
  'PWG_TOKEN' => get_pwg_token(),
  'WATERMARK' => $watermark,
  'WATERMARK_OPTIONS' => $watermark_options,
  'WATERMARK_PROFILES' => $profiles,
  'WATERMARK_DEFAULTS' => $defaults,
  'WATERMARK_CATEGORIES' => $categories,
  'WATERMARK_ENGINE' => $engine,
  'PRECACHE_STATUS_URL' => get_root_url().'plugins/'.BRATONIEN_TOOLS_ID.'/precache-status.php',
));

$template->set_filename('plugin_admin_content', BRATONIEN_TOOLS_PATH . 'template/admin.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
