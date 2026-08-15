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
  'WATERMARK_PROFILES' => $profiles,
  'WATERMARK_DEFAULTS' => $defaults,
  'WATERMARK_CATEGORIES' => $categories,
  'WATERMARK_ENGINE' => $engine,
));

$template->set_filename('plugin_admin_content', BRATONIEN_TOOLS_PATH . 'template/admin.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
