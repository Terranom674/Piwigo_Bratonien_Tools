<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

check_status(ACCESS_ADMINISTRATOR);

require_once(BRATONIEN_TOOLS_PATH . 'include/tool_registry.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_system.inc.php');

function bratonien_tools_nc_connector_admin_connections(array $connections)
{
  $by_id = array();
  foreach ($connections as $connection)
  {
    $by_id[(int)$connection['id']] = $connection;
  }

  $hidden_legacy_ids = array();
  $logical_remote = array();
  foreach ($connections as $connection)
  {
    if ((string)($connection['adapter'] ?? '') !== 'remote') continue;
    $migration = isset($connection['config']['migration']) && is_array($connection['config']['migration'])
      ? $connection['config']['migration']
      : array();
    if ((string)($migration['role'] ?? '') !== 'webdav-primary-candidate') continue;

    $legacy_id = (int)($migration['legacy_fallback_connection_id'] ?? 0);
    if ($legacy_id < 1 || empty($by_id[$legacy_id]) || (string)$by_id[$legacy_id]['adapter'] !== 'local') continue;

    $legacy = $by_id[$legacy_id];
    $hidden_legacy_ids[$legacy_id] = true;
    $connection['logical_connection'] = true;
    $connection['legacy_fallback_connection_id'] = $legacy_id;
    $connection['enabled'] = !empty($connection['enabled']) || !empty($legacy['enabled']);
    if ($connection['enabled']) $connection['takeover_state'] = 'active';
    $connection['fallback_stored'] = !empty($connection['fallback_stored']) || !empty($legacy['fallback_stored']);
    if (trim((string)$connection['name']) === '') $connection['name'] = (string)$legacy['name'];
    $logical_remote[(int)$connection['id']] = $connection;
  }

  $visible = array();
  foreach ($connections as $connection)
  {
    $id = (int)$connection['id'];
    if (isset($hidden_legacy_ids[$id])) continue;
    if (isset($logical_remote[$id])) $connection = $logical_remote[$id];
    $visible[] = $connection;
  }

  return $visible;
}

$tools = bratonien_tools_get_tools();
$messages = array();
$errors = array();
$self_update_override = null;
$nc_piwigo_api_test = null;

// Post/Redirect/Get: never leave a mutating Bratonien Tools action as the
// browser's current request. Otherwise a normal reload can submit the same
// action again (for example an update, delete or create action).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['bratonien_tools_flash']) && is_array($_SESSION['bratonien_tools_flash']))
{
  $flash = $_SESSION['bratonien_tools_flash'];
  unset($_SESSION['bratonien_tools_flash']);

  if (!empty($flash['messages']) && is_array($flash['messages']))
  {
    $messages = array_values($flash['messages']);
  }
  if (!empty($flash['errors']) && is_array($flash['errors']))
  {
    $errors = array_values($flash['errors']);
  }
  if (!empty($flash['self_update']) && is_array($flash['self_update']))
  {
    $self_update_override = $flash['self_update'];
  }
  if (!empty($flash['nc_piwigo_api_test']) && is_array($flash['nc_piwigo_api_test']))
  {
    $nc_piwigo_api_test = $flash['nc_piwigo_api_test'];
  }
}

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
      if (!empty($result['nc_piwigo_api_test']) && is_array($result['nc_piwigo_api_test']))
      {
        $nc_piwigo_api_test = $result['nc_piwigo_api_test'];
      }
    }
    catch (Throwable $e)
    {
      $errors[] = $e->getMessage();
    }
  }

  $_SESSION['bratonien_tools_flash'] = array(
    'messages' => $messages,
    'errors' => $errors,
    'self_update' => $self_update_override,
    'nc_piwigo_api_test' => $nc_piwigo_api_test,
  );

  $redirect_url = get_root_url().'admin.php?page=plugin-'.BRATONIEN_TOOLS_ID;
  $wizard_action = strpos($tool_id, 'nc_connector_wizard_') === 0
    || $tool_id === 'nc_connector_migrate_start'
    || $tool_id === 'nc_connector_edit_start';
  if ($wizard_action)
  {
    $wizard_closed = $tool_id === 'nc_connector_wizard_reset'
      || ($tool_id === 'nc_connector_wizard_finish' && empty($errors));
    if (($tool_id === 'nc_connector_migrate_start' || $tool_id === 'nc_connector_edit_start') && !empty($errors))
    {
      $wizard_closed = true;
    }
    $redirect_url .= '&nc_wizard='.($wizard_closed ? 'closed' : 'open');
  }
  if (!headers_sent())
  {
    header('Location: '.$redirect_url, true, 303);
    exit;
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
$nc_connector = bratonien_tools_nc_connector_status();
$nc_connector_runtime_connections = $nc_connector['connections'];
$nc_connector['connections'] = bratonien_tools_nc_connector_admin_connections($nc_connector_runtime_connections);
$nc_connector['connection_count'] = count($nc_connector['connections']);
$nc_connector['active_count'] = 0;
foreach ($nc_connector['connections'] as $visible_connection)
{
  if (!empty($visible_connection['enabled'])) $nc_connector['active_count']++;
}
foreach ($nc_connector['connections'] as &$nc_connection)
{
  $nc_connection['last_sync'] = bratonien_tools_nc_connector_connection_last_status($nc_connection);
  $nc_connection['display_name'] = $nc_connection['name'];

  $storage_lines = array();
  $storages = isset($nc_connection['config']['storages']) && is_array($nc_connection['config']['storages'])
    ? $nc_connection['config']['storages']
    : array();
  foreach ($storages as $storage)
  {
    $storage_lines[] = (string)($storage['storage_id'] ?? '').' | '.(string)($storage['source_prefix'] ?? '').' | '.(string)($storage['local_mount'] ?? '');
  }
  $nc_connection['storage_text'] = implode("\n", $storage_lines);

  if (!empty($nc_connection['fallback_stored']))
  {
    $nc_connection['display_name'] .= ' · Fallback gespeichert';
    $nc_connection['verification_checks'][] = array(
      'name' => 'Piwigo-Fallback',
      'ok' => true,
      'detail' => 'Benutzername/Passwort verschluesselt gespeichert',
    );
  }
}
unset($nc_connection);
$nc_connector['piwigo_api_test'] = $nc_piwigo_api_test;
$nc_connector['wizard'] = bratonien_tools_nc_wizard_state();
$nc_system_defaults = array(
  'timer_name' => 'bratonien-nc-connector.timer',
  'timer_active' => false,
  'timer_enabled' => false,
  'last_run_timestamp' => 0,
  'last_run_label' => 'Nicht verfügbar',
  'last_run_state' => '',
  'last_run_message' => '',
  'last_run_auth_mode' => '',
  'last_run_api_state' => '',
  'last_run_api_message' => '',
  'last_run_fallback_state' => '',
  'last_run_fallback_message' => '',
  'last_run_error_detail' => '',
  'next_run_timestamp' => 0,
  'next_run_label' => 'Nicht verfügbar',
  'legacy_runtime_exists' => false,
  'legacy_config_exists' => false,
  'legacy_service_exists' => false,
  'legacy_timer_exists' => false,
);
$nc_connector['system'] = array_merge(
  $nc_system_defaults,
  bratonien_tools_nc_connector_system_status($nc_connector_runtime_connections)
);
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
  'NC_CONNECTOR' => $nc_connector,
  'SELF_UPDATE' => $self_update,
  'SELF_UPDATE_ENV' => $self_update_environment,
  'MAIN_CACHE_STATUS_URL' => get_absolute_root_url(true).'plugins/'.BRATONIEN_TOOLS_ID.'/main-cache-status.php',
));

$template->set_filename('admin_tabs_content', BRATONIEN_TOOLS_PATH . 'template/admin_tabs.tpl');
$template->set_filename('plugin_admin_content', BRATONIEN_TOOLS_PATH . 'template/admin.tpl');
$template->set_filename('public_selection_admin_content', BRATONIEN_TOOLS_PATH . 'template/public_selection_admin.tpl');
$template->set_filename('asset_manager_admin_content', BRATONIEN_TOOLS_PATH . 'template/asset_manager_admin.tpl');
$template->set_filename('album_shares_admin_content', BRATONIEN_TOOLS_PATH . 'template/album_shares_admin.tpl');
$template->set_filename('nc_connector_admin_content', BRATONIEN_TOOLS_PATH . 'template/nc_connector_admin.tpl');
$template->set_filename('system_admin_content', BRATONIEN_TOOLS_PATH . 'template/system_admin.tpl');

$admin_content = $template->parse('admin_tabs_content', true);
$admin_content .= $template->parse('plugin_admin_content', true);
$admin_content .= $template->parse('public_selection_admin_content', true);
$admin_content .= $template->parse('asset_manager_admin_content', true);
$admin_content .= $template->parse('album_shares_admin_content', true);
$admin_content .= $template->parse('nc_connector_admin_content', true);
$admin_content .= $template->parse('system_admin_content', true);
$template->assign('ADMIN_CONTENT', $admin_content);