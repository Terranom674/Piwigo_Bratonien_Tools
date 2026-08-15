<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_watermark_engine_enabled()
{
  $config = bratonien_tools_get_watermark_engine_config();
  return !empty($config['enabled']);
}

function bratonien_tools_get_watermark_engine_config()
{
  $default = array(
    'enabled' => false,
    'restore_piwigo' => true,
  );

  if (!function_exists('conf_get_param'))
  {
    return $default;
  }

  $stored = conf_get_param('bratonien_watermark_engine', null);
  if (empty($stored))
  {
    return $default;
  }

  $decoded = json_decode($stored, true);
  return is_array($decoded) ? array_merge($default, $decoded) : $default;
}

function bratonien_tools_set_watermark_engine($enabled)
{
  if (!function_exists('conf_update_param'))
  {
    throw new RuntimeException('Piwigo-Konfiguration ist nicht verfuegbar.');
  }

  $config = bratonien_tools_get_watermark_engine_config();
  $config['enabled'] = (bool) $enabled;

  conf_update_param('bratonien_watermark_engine', json_encode($config));
}

function bratonien_tools_disable_piwigo_watermarks()
{
  if (!class_exists('ImageStdParams'))
  {
    require_once(PHPWG_ROOT_PATH . 'include/derivative_std_params.inc.php');
  }

  foreach (ImageStdParams::get_defined_type_map() as $params)
  {
    $params->use_watermark = false;
  }

  ImageStdParams::save();
}
