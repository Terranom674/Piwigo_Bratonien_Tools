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

function bratonien_tools_validate_default_profile($value)
{
  if ($value === '' || $value === null)
  {
    return null;
  }

  $id = (int)$value;
  $profile = $id > 0 ? bratonien_tools_get_watermark_profile($id) : null;
  if (!$profile || empty($profile['active']))
  {
    throw new RuntimeException('Ungueltiges oder inaktives Wasserzeichenprofil in den globalen Regeln.');
  }

  return $id;
}

function bratonien_tools_save_watermark_defaults()
{
  $config = array(
    'public_profile' => bratonien_tools_validate_default_profile($_POST['public_profile'] ?? null),
    'private_profile' => bratonien_tools_validate_default_profile($_POST['private_profile'] ?? null),
  );

  conf_update_param('bratonien_watermark_defaults', json_encode($config));

  if (function_exists('bratonien_tools_presentation_refresh_enqueue_all'))
  {
    bratonien_tools_presentation_refresh_enqueue_all('global-watermark-rules-changed');
  }

  return array('message'=>'Globale Wasserzeichenregeln gespeichert. Vorschauen werden im Hintergrund an die neuen Regeln angepasst.');
}
