<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(dirname(__FILE__) . '/include/database.class.php');

class bratonien_tools_maintain extends PluginMaintain
{
  public function install($plugin_version, &$errors = array())
  {
    bratonien_tools_create_tables();
  }

  public function activate($plugin_version, &$errors = array())
  {
    bratonien_tools_create_tables();
  }

  public function deactivate()
  {
  }

  public function update($old_version, $new_version, &$errors = array())
  {
    bratonien_tools_create_tables();
  }

  public function uninstall()
  {
    bratonien_tools_drop_tables();
  }
}
