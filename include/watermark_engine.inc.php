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
    'piwigo_backup' => null,
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

function bratonien_tools_save_watermark_engine_config($config)
{
  conf_update_param('bratonien_watermark_engine', json_encode($config));
}

function bratonien_tools_set_watermark_engine($enabled)
{
  if (!function_exists('conf_update_param'))
  {
    throw new RuntimeException('Piwigo-Konfiguration ist nicht verfuegbar.');
  }

  $config = bratonien_tools_get_watermark_engine_config();

  if ($enabled && empty($config['enabled']))
  {
    $config['piwigo_backup'] = bratonien_tools_backup_piwigo_watermark_settings();
    bratonien_tools_disable_piwigo_watermarks();
  }

  if (!$enabled && !empty($config['enabled']) && !empty($config['restore_piwigo']))
  {
    bratonien_tools_restore_piwigo_watermark_settings($config['piwigo_backup']);
  }

  $config['enabled'] = (bool) $enabled;
  bratonien_tools_save_watermark_engine_config($config);
}

function bratonien_tools_backup_piwigo_watermark_settings()
{
  if (!class_exists('ImageStdParams'))
  {
    require_once(PHPWG_ROOT_PATH . 'include/derivative_std_params.inc.php');
  }

  $backup = array();

  foreach (ImageStdParams::get_defined_type_map() as $type => $params)
  {
    $backup[$type] = array(
      'use_watermark' => (bool) $params->use_watermark,
    );
  }

  return $backup;
}

function bratonien_tools_restore_piwigo_watermark_settings($backup)
{
  if (!is_array($backup))
  {
    return;
  }

  if (!class_exists('ImageStdParams'))
  {
    require_once(PHPWG_ROOT_PATH . 'include/derivative_std_params.inc.php');
  }

  foreach (ImageStdParams::get_defined_type_map() as $type => $params)
  {
    if (isset($backup[$type]['use_watermark']))
    {
      $params->use_watermark = (bool) $backup[$type]['use_watermark'];
    }
  }

  ImageStdParams::save();
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
