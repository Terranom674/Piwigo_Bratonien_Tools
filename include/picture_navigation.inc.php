<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_picture_navigation_render()
{
  global $template;

  if (!isset($template))
  {
    return;
  }

  $template->assign(
    'BRATONIEN_PICTURE_NAV_PATH',
    'plugins/'.BRATONIEN_TOOLS_ID
  );

  $template->set_filename(
    'bratonien_picture_navigation_assets',
    BRATONIEN_TOOLS_PATH.'template/picture_navigation_assets.tpl'
  );
  $template->parse('bratonien_picture_navigation_assets', false);
}

add_event_handler(
  'loc_end_picture',
  'bratonien_tools_picture_navigation_render',
  EVENT_HANDLER_PRIORITY_NEUTRAL + 30
);
