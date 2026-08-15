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

  $tool_id = (string) $_POST['bratonien_tool'];

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
      $errors[] = 'Werkzeug fehlgeschlagen: ' . $e->getMessage();
    }
  }
}

$template->assign(array(
  'BRATONIEN_TOOLS' => $tools,
  'BRATONIEN_MESSAGES' => $messages,
  'BRATONIEN_ERRORS' => $errors,
  'PWG_TOKEN' => get_pwg_token(),
));

$template->set_filename('plugin_admin_content', BRATONIEN_TOOLS_PATH . 'template/admin.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
