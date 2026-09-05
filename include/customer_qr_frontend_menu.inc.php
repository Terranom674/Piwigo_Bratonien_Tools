<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_customer_qr_register_frontend_menu($menu_ref_array)
{
  if (defined('IN_ADMIN'))
  {
    return;
  }

  $settings = bratonien_tools_customer_qr_settings();
  if (empty($settings['enabled']))
  {
    return;
  }

  if (!is_array($menu_ref_array) || empty($menu_ref_array[0]) || !is_object($menu_ref_array[0]))
  {
    return;
  }

  $menu = $menu_ref_array[0];
  if (!method_exists($menu, 'get_id') || $menu->get_id() !== 'menubar' || !method_exists($menu, 'register_block'))
  {
    return;
  }

  if (!class_exists('RegisteredBlock'))
  {
    return;
  }

  $menu->register_block(new RegisteredBlock(
    'mbBratonienCustomerQr',
    'QR-Code hochladen',
    BRATONIEN_TOOLS_ID
  ));
}

function bratonien_tools_customer_qr_prepare_frontend_menu($menu_ref_array)
{
  if (defined('IN_ADMIN'))
  {
    return;
  }

  $settings = bratonien_tools_customer_qr_settings();
  if (empty($settings['enabled']))
  {
    return;
  }

  if (!is_array($menu_ref_array) || empty($menu_ref_array[0]) || !is_object($menu_ref_array[0]))
  {
    return;
  }

  $menu = $menu_ref_array[0];
  if (!method_exists($menu, 'get_id') || $menu->get_id() !== 'menubar')
  {
    return;
  }

  $block = $menu->get_block('mbBratonienCustomerQr');
  if ($block === null)
  {
    return;
  }

  $url = htmlspecialchars(
    get_root_url().'plugins/'.BRATONIEN_TOOLS_ID.'/customer-qr-upload.php',
    ENT_QUOTES,
    'UTF-8'
  );

  // Bootstrap Darkroom renders raw-content blocks directly in the root navbar.
  // Keeping this as a separate Piwigo menu block avoids hiding the customer
  // action inside mbMenu/"Entdecken" and still follows the normal menubar
  // lifecycle instead of modifying the theme.
  $block->raw_content = '<li class="nav-item bratonien-customer-qr-nav">'
    .'<a class="nav-link" href="'.$url.'" title="QR-Code hochladen">'
    .'<i class="fas fa-qrcode fa-fw" aria-hidden="true"></i> QR-Code hochladen'
    .'</a></li>';

  if (method_exists($menu, 'set_block_position'))
  {
    // Core defaults: Specials=200, Menu=250. Darkroom combines those two as
    // "Entdecken"; 225 therefore places QR upload directly after it.
    $menu->set_block_position('mbBratonienCustomerQr', 225);
  }
}

add_event_handler(
  'blockmanager_register_blocks',
  'bratonien_tools_customer_qr_register_frontend_menu',
  EVENT_HANDLER_PRIORITY_NEUTRAL + 10
);
add_event_handler(
  'blockmanager_prepare_display',
  'bratonien_tools_customer_qr_prepare_frontend_menu',
  EVENT_HANDLER_PRIORITY_NEUTRAL + 10
);
