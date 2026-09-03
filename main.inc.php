<?php
/*
Plugin Name: Bratonien Tools
Version: 0.9.7.1.15
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
require_once(BRATONIEN_TOOLS_PATH . 'include/webdav_cache_validation.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/webdav_materialize_runtime.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/public_selection.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/picture_navigation.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/batch_titles.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/album_shares.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_orphan_ws.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_productive_ws.inc.php');

add_event_handler('get_admin_plugin_menu_links', 'bratonien_tools_admin_menu');
add_event_handler('get_derivative_url', 'bratonien_tools_filter_derivative_url', EVENT_HANDLER_PRIORITY_NEUTRAL, 4);
add_event_handler('get_src_image_url', 'bratonien_tools_filter_webdav_materialize_src_url', EVENT_HANDLER_PRIORITY_NEUTRAL + 50, 2);
add_event_handler('get_derivative_url', 'bratonien_tools_filter_webdav_materialize_derivative_url', EVENT_HANDLER_PRIORITY_NEUTRAL + 50, 4);
add_event_handler('loc_end_element_set_global', 'bratonien_tools_batch_titles_register_action');
add_event_handler('element_set_global_action', 'bratonien_tools_batch_titles_apply', EVENT_HANDLER_PRIORITY_NEUTRAL, 2);
add_event_handler('init', 'bratonien_tools_prepare_connector_private_import', EVENT_HANDLER_PRIORITY_NEUTRAL - 30);
add_event_handler('init', 'bratonien_tools_prepare_private_album_permissions', EVENT_HANDLER_PRIORITY_NEUTRAL - 20);
add_event_handler('init', 'bratonien_tools_preserve_private_album_access', EVENT_HANDLER_PRIORITY_NEUTRAL - 10);
add_event_handler('init', 'bratonien_tools_preserve_connector_top_level_access', EVENT_HANDLER_PRIORITY_NEUTRAL - 9);
add_event_handler('init', 'bratonien_tools_album_shares_init');
add_event_handler('loc_end_intro', 'bratonien_tools_fix_admin_album_stat_tile');
add_event_handler('delete_categories', 'bratonien_tools_album_shares_on_delete_categories');
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

  bratonien_tools_grant_private_album_access($category_id, (int)$user['id']);
}

function bratonien_tools_preserve_connector_top_level_access()
{
  global $user;

  if (
    !defined('IN_ADMIN')
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || (string)($_GET['page'] ?? '') !== 'site_update'
    || (string)($_POST['bratonien_connector'] ?? '') !== '1'
    || empty($user['id'])
  )
  {
    return;
  }

  $site_id = (int)($_GET['site'] ?? 0);
  $user_id = (int)$user['id'];
  if ($site_id < 1 || $user_id < 1)
  {
    return;
  }

  register_shutdown_function(function () use ($site_id, $user_id) {
    $query = '\nSELECT id\n  FROM '.CATEGORIES_TABLE.'\n  WHERE site_id = '.$site_id.'\n    AND dir IS NOT NULL\n    AND id_uppercat IS NULL\n    AND status = \'private\'\n;';
    $result = pwg_query($query);
    $changed = false;
    while ($row = pwg_db_fetch_assoc($result))
    {
      $category_id = (int)$row['id'];
      if ($category_id < 1)
      {
        continue;
      }

      $access_query = '\nSELECT 1\n  FROM '.USER_ACCESS_TABLE.'\n  WHERE user_id = '.$user_id.'\n    AND cat_id = '.$category_id.'\n  LIMIT 1\n;';
      if (pwg_db_num_rows(pwg_query($access_query)) > 0)
      {
        continue;
      }

      bratonien_tools_grant_private_album_access($category_id, $user_id);
      $changed = true;
    }

    if ($changed && function_exists('invalidate_user_cache'))
    {
      invalidate_user_cache(true);
    }
  });
}

function bratonien_tools_grant_private_album_access($category_id, $user_id)
{
  $category_id = (int)$category_id;
  $user_id = (int)$user_id;
  if ($category_id < 1 || $user_id < 1)
  {
    return;
  }

  $query = '\nSELECT 1\n  FROM '.USER_ACCESS_TABLE.'\n  WHERE user_id = '.$user_id.'\n    AND cat_id = '.$category_id.'\n  LIMIT 1\n;';
  $result = pwg_query($query);
  if (pwg_db_num_rows($result) > 0)
  {
    return;
  }

  single_insert(
    USER_ACCESS_TABLE,
    array(
      'user_id' => $user_id,
      'cat_id' => $category_id,
    )
  );
}

function bratonien_tools_fix_admin_album_stat_tile()
{
  global $template;

  if (!defined('IN_ADMIN'))
  {
    return;
  }

  $template->set_prefilter('intro', 'bratonien_tools_prefilter_admin_album_stat_tile');
}

function bratonien_tools_prefilter_admin_album_stat_tile($source)
{
  return str_replace('{if $NB_ALBUMS > 1}', '{if $NB_ALBUMS > 0}', $source);
}

function bratonien_tools_admin_menu($menu)
{
  $menu[] = array(
    'NAME' => 'Bratonien Tools',
    'URL'  => get_root_url() . 'admin.php?page=plugin-' . BRATONIEN_TOOLS_ID,
  );
  return $menu;
}
?>