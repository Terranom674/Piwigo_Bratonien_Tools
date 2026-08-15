<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_get_watermark_defaults()
{
  $defaults = conf_get_param('bratonien_watermark_defaults', null);
  $defaults = $defaults ? json_decode($defaults, true) : array();

  return array_merge(array(
    'public_profile' => null,
    'private_profile' => null,
  ), is_array($defaults) ? $defaults : array());
}

function bratonien_tools_save_watermark_defaults()
{
  $config = array(
    'public_profile' => !empty($_POST['public_profile']) ? (int)$_POST['public_profile'] : null,
    'private_profile' => !empty($_POST['private_profile']) ? (int)$_POST['private_profile'] : null,
  );

  conf_update_param('bratonien_watermark_defaults', json_encode($config));

  return array('message'=>'Globale Wasserzeichenprofile gespeichert.');
}
