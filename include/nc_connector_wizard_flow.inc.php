<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_wizard_save_technical_flow()
{
  $result = bratonien_tools_nc_wizard_save_technical_with_known_database();
  $state = bratonien_tools_nc_wizard_state();

  if ((string)($state['technical_stage'] ?? '') === 'mounts')
  {
    if (!empty($state['directory_selection_ready']))
    {
      bratonien_tools_nc_wizard_refresh_directory_state($state, '');
    }
    else
    {
      $state['mount_prompted'] = true;
    }
    bratonien_tools_nc_wizard_store($state);
  }

  return $result;
}
