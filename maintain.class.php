<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(dirname(__FILE__) . '/include/album_shares.inc.php');
require_once(dirname(__FILE__) . '/include/database.class.php');
require_once(dirname(__FILE__) . '/tools/watermark_profiles.inc.php');
require_once(dirname(__FILE__) . '/include/dependencies.inc.php');

class bratonien_tools_maintain extends PluginMaintain
{
  private function prepare(&$errors = array())
  {
    bratonien_tools_create_tables();
    bratonien_tools_create_default_watermark_profiles();

    $dependency_messages = array();
    bratonien_tools_ensure_dependencies($dependency_messages);

    if (function_exists('conf_update_param'))
    {
      conf_update_param('bratonien_dependency_status', json_encode(array(
        'checked_at' => time(),
        'messages' => $dependency_messages,
      )));
    }
  }

  public function install($plugin_version, &$errors = array())
  {
    $this->prepare($errors);
  }

  public function activate($plugin_version, &$errors = array())
  {
    $this->prepare($errors);
  }

  public function deactivate()
  {
  }

  public function update($old_version, $new_version, &$errors = array())
  {
    $this->prepare($errors);
  }

  public function uninstall()
  {
    bratonien_tools_drop_tables();
  }
}
