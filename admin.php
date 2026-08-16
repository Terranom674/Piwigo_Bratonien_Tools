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
$self_update_override = null;

if (
  $_SERVER['REQUEST_METHOD'] === 'POST'
  && empty($_POST)
  && empty($_FILES)
  && !empty($_SERVER['CONTENT_LENGTH'])
)
{
  $errors[] = 'Upload abgelehnt: Die Anfrage ist groesser als das PHP-Limit post_max_size ('
    .ini_get('post_max_size').'). Das Datei-Limit upload_max_filesize liegt bei '
    .ini_get('upload_max_filesize').'.';
}

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
      if (!empty($result['self_update']) && is_array($result['self_update']))
      {
        $self_update_override = $result['self_update'];
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
$cache_workers = bratonien_tools_get_cache_worker_settings();
$public_selection = bratonien_tools_get_public_selection_settings();
$public_selection_groups = bratonien_tools_get_piwigo_groups();
$assets = bratonien_tools_get_assets();
$asset_environment = bratonien_tools_get_asset_environment();
$album_shares = bratonien_tools_get_album_shares();
$private_albums = bratonien_tools_get_private_albums();
$album_lock_page_number = isset($_GET['br_album_page']) ? max(1, (int)$_GET['br_album_page']) : 1;
$album_lock_search = isset($_GET['br_album_search']) ? trim((string)$_GET['br_album_search']) : '';
$album_lock_page = bratonien_tools_get_album_lock_page($album_lock_page_number, 10, $album_lock_search);
$album_pager_url = get_root_url().'admin.php?page=plugin-'.BRATONIEN_TOOLS_ID;
if ($album_lock_search !== '')
{
  $album_pager_url .= '&amp;br_album_search='.rawurlencode($album_lock_search);
}
$album_pager_url .= '&amp;br_album_page=';
$self_update = is_array($self_update_override) ? $self_update_override : bratonien_tools_remote_update_info(false);
$self_update_environment = array(
  'webmaster' => function_exists('is_webmaster') ? is_webmaster() : false,
  'plugins_writable' => is_writable(dirname(rtrim(BRATONIEN_TOOLS_PATH, '/'))),
  'zip' => class_exists('ZipArchive'),
);

$selected_public_selection_groups = array_fill_keys($public_selection['groups'], true);
foreach ($public_selection_groups as &$group)
{
  $group['selected'] = isset($selected_public_selection_groups[(int)$group['id']]);
}
unset($group);

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
  'CACHE_WORKERS' => $cache_workers,
  'PUBLIC_SELECTION' => $public_selection,
  'PUBLIC_SELECTION_GROUPS' => $public_selection_groups,
  'BRATONIEN_ASSETS' => $assets,
  'ASSET_ENV' => $asset_environment,
  'BRATONIEN_ALBUM_SHARES' => $album_shares,
  'BRATONIEN_PRIVATE_ALBUMS' => $private_albums,
  'BRATONIEN_ALBUM_LOCK_PAGE' => $album_lock_page,
  'BRATONIEN_ALBUM_PAGER_URL' => $album_pager_url,
  'BRATONIEN_ALBUM_SEARCH' => $album_lock_search,
  'SELF_UPDATE' => $self_update,
  'SELF_UPDATE_ENV' => $self_update_environment,
  'MAIN_CACHE_STATUS_URL' => get_absolute_root_url(true).'plugins/'.BRATONIEN_TOOLS_ID.'/main-cache-status.php',
));

$template->set_filename('admin_tabs_content', BRATONIEN_TOOLS_PATH . 'template/admin_tabs.tpl');
$template->set_filename('plugin_admin_content', BRATONIEN_TOOLS_PATH . 'template/admin.tpl');
$template->set_filename('public_selection_admin_content', BRATONIEN_TOOLS_PATH . 'template/public_selection_admin.tpl');
$template->set_filename('asset_manager_admin_content', BRATONIEN_TOOLS_PATH . 'template/asset_manager_admin.tpl');
$template->set_filename('album_shares_admin_content', BRATONIEN_TOOLS_PATH . 'template/album_shares_admin.tpl');
$template->set_filename('system_admin_content', BRATONIEN_TOOLS_PATH . 'template/system_admin.tpl');

$admin_content = $template->parse('admin_tabs_content', true);
$admin_content .= $template->parse('plugin_admin_content', true);
$admin_content .= $template->parse('public_selection_admin_content', true);
$admin_content .= $template->parse('asset_manager_admin_content', true);
$admin_content .= $template->parse('album_shares_admin_content', true);
$admin_content .= $template->parse('system_admin_content', true);
$template->assign('ADMIN_CONTENT', $admin_content);
