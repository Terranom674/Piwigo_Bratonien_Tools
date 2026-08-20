<?php
/*
Plugin Name: Bratonien Tools
Version: 0.9.7.24
Description: Erweiterbare Administrationswerkzeuge fuer die Bratonien-Piwigo-Installation.
Plugin URI: https://github.com/Terranom674/Piwigo_Bratonien_Tools
Author: Bratonien
*/

if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

define('BRATONIEN_TOOLS_ID', basename(dirname(__FILE__)));
define('BRATONIEN_TOOLS_PATH', PHPWG_PLUGINS_PATH . BRATONIEN_TOOLS_ID . '/');

require_once(BRATONIEN_TOOLS_PATH . 'include/watermark_runtime.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/webdav_image_runtime.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/public_selection.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/picture_navigation.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/batch_titles.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/album_shares.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_ws.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_orphan_ws.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_productive_ws.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_scheduler.inc.php');

add_event_handler('get_admin_plugin_menu_links', 'bratonien_tools_admin_menu');
add_event_handler('get_derivative_url', 'bratonien_tools_filter_derivative_url', EVENT_HANDLER_PRIORITY_NEUTRAL, 4);
add_event_handler('get_src_image_url', 'bratonien_tools_filter_webdav_src_url', EVENT_HANDLER_PRIORITY_NEUTRAL + 50, 2);
add_event_handler('get_derivative_url', 'bratonien_tools_filter_webdav_derivative_url', EVENT_HANDLER_PRIORITY_NEUTRAL + 50, 4);
add_event_handler('loc_end_element_set_global', 'bratonien_tools_batch_titles_register_action');
add_event_handler('element_set_global_action', 'bratonien_tools_batch_titles_apply', EVENT_HANDLER_PRIORITY_NEUTRAL, 2);
add_event_handler('init', 'bratonien_tools_prepare_connector_private_import', EVENT_HANDLER_PRIORITY_NEUTRAL - 30);
add_event_handler('init', 'bratonien_tools_prepare_private_album_permissions', EVENT_HANDLER_PRIORITY_NEUTRAL - 20);
add_event_handler('init', 'bratonien_tools_preserve_private_album_access', EVENT_HANDLER_PRIORITY_NEUTRAL - 10);
add_event_handler('init', 'bratonien_tools_album_shares_init');
add_event_handler('delete_categories', 'bratonien_tools_album_shares_on_delete_categories');
add_event_handler('ws_add_methods', 'bratonien_tools_register_ws_methods');
add_event_handler('ws_add_methods', 'bratonien_tools_register_nc_orphan_ws_methods');
add_event_handler('ws_add_methods', 'bratonien_tools_register_nc_productive_ws_methods');

function bratonien_tools_prepare_connector_private_import()
{
  global $conf;

  if (
    !defined('IN_ADMIN')
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || (string)($_GET['page'] ?? '') !== 'site_update'
    || (string)($_POST['bratonien_connector'] ?? '') !== '1'
  )
  {
    return;
  }

  $conf['newcat_default_status'] = 'private';
}

function bratonien_tools_prepare_private_album_permissions()
{
  global $user;

  if (
    !defined('IN_ADMIN')
    || $_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($user['id'])
    || (string)($_POST['status'] ?? '') !== 'private'
  )
  {
    return;
  }

  $page = (string)($_GET['page'] ?? '');
  if (!preg_match('/^album-(\d+)-permissions$/', $page, $matches))
  {
    return;
  }

  $category_id = (int)$matches[1];
  if ($category_id < 1)
  {
    return;
  }

  $result = pwg_query('SELECT status FROM '.CATEGORIES_TABLE.' WHERE id = '.$category_id.' LIMIT 1');
  if (!pwg_db_num_rows($result))
  {
    return;
  }

  $category = pwg_db_fetch_assoc($result);
  if ($category['status'] !== 'public')
  {
    return;
  }

  $users = isset($_POST['users']) && is_array($_POST['users']) ? $_POST['users'] : array();
  $current_user_id = (int)$user['id'];
  $normalized_user_ids = array_map('intval', $users);

  if (!in_array($current_user_id, $normalized_user_ids, true))
  {
    $users[] = $current_user_id;
  }

  $_POST['users'] = $users;
}

function bratonien_tools_admin_menu($menu)
{
  $menu[] = array(
    'NAME' => 'Bratonien Tools',
    'URL' => get_root_url() . 'admin.php?page=plugin-' . BRATONIEN_TOOLS_ID,
  );

  return $menu;
}
