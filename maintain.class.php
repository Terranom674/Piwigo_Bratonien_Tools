<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(dirname(__FILE__) . '/include/database.class.php');
require_once(dirname(__FILE__) . '/tools/watermark_profiles.inc.php');

class bratonien_tools_maintain extends PluginMaintain
{
  private function prepare()
  {
    bratonien_tools_create_tables();
    bratonien_tools_create_default_watermark_profiles();
  }

  public function install($plugin_version, &$errors = array())
  {
    $this->prepare();
  }

  public function activate($plugin_version, &$errors = array())
  {
    $this->prepare();
  }

  public function deactivate()
  {
  }

  public function update($old_version, $new_version, &$errors = array())
  {
    $this->prepare();
  }

  public function uninstall()
  {
    bratonien_tools_drop_tables();
  }
}
