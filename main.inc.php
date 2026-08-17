<?php
/*
Plugin Name: Bratonien Tools
Version: 0.14.2
Description: Erweiterbare Administrationswerkzeuge fuer die Bratonien-Piwigo-Installation.
Plugin URI: https://github.com/Terranom674/Piwigo_Bratonien_Tools
Author: Bratonien
*/

if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

const BRATONIEN_TOOLS_ID = 'bratonien_tools';
const BRATONIEN_TOOLS_VERSION = '0.14.2';
define('BRATONIEN_TOOLS_PATH', PHPWG_PLUGINS_PATH . BRATONIEN_TOOLS_ID . '/');

add_event_handler('get_admin_plugin_menu_links', 'bratonien_tools_admin_menu');
add_event_handler('loc_end_page_header', 'bratonien_tools_load_public_assets');

require_once(BRATONIEN_TOOLS_PATH . 'include/admin.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/public.inc.php');
