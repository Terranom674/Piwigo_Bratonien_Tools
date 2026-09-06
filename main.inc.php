<?php
/*
Plugin Name: Bratonien Tools
Version: 0.9.7.1.45
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
require_once(BRATONIEN_TOOLS_PATH . 'include/presentation_refresh.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/public_selection.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/picture_navigation.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/batch_titles.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/album_shares.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_orphan_ws.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_productive_ws.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/customer_qr_upload.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/customer_qr_frontend_menu.inc.php');

add_event_handler('get_admin_plugin_menu_links', 'bratonien_tools_admin_menu');
add_event_handler('get_derivative_url', 'bratonien_tools_filter_derivative_url', EVENT_HANDLER_PRIORITY_NEUTRAL, 4);
add_event_handler('get_src_image_url', 'bratonien_tools_filter_webdav_materialize_src_url', EVENT_HANDLER_PRIORITY_NEUTRAL + 50, 2);
add_event_handler('get_derivative_url', 'bratonien_tools_filter_webdav_materialize_derivative_url', EVENT_HANDLER_PRIORITY_NEUTRAL + 50, 4);
add_event_handler('loc_end_element_set_global', 'bratonien_tools_batch_titles_register_action');
add_event_handler('element_set_global_action', 'bratonien_tools_batch_titles_apply', EVENT_HANDLER_PRIORITY_NEUTRAL, 2);
add_event_handler('init', 'bratonien_tools_prepare_connector_private_import', EVENT_HANDLER_PRIORITY_NEUTRAL - 30);
add_event_handler('init', 'bratonien_tools_prepare_private_album_permissions', EVENT_HANDLER_PRIORITY_NEUTRAL - 20);
add_event_handler('init', 'bratonien_tools_preserve_private_album_access', EVENT_HANDLER_PRIORITY_NEUTRAL - 10);

function bratonien_tools_admin_menu($menu)
{
  $menu[] = array(
    'NAME' => 'Bratonien Tools',
    'URL' => get_root_url().'admin.php?page=plugin-'.BRATONIEN_TOOLS_ID,
  );
  return $menu;
}
