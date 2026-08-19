<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(BRATONIEN_TOOLS_PATH . 'include/watermark_base.inc.php');

function bratonien_tools_watermark_engine_enabled()
{
  $config = bratonien_tools_get_watermark_engine_config();
  $enabled = !empty($config['enabled']);

  // Piwigo recalculates use_watermark for custom derivatives from the native
  // watermark file itself. Therefore an active Bratonien engine must keep the
  // native watermark file empty, not only the stored use_watermark flags false.
  if ($enabled)
  {
    bratonien_tools_disable_piwigo_watermarks();
  }

  return $enabled;
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
  }

  if (!$enabled && !empty($config['enabled']) && !empty($config['restore_piwigo']))
  {
    bratonien_tools_restore_piwigo_watermark_settings($config['piwigo_backup']);
  }

  $config['enabled'] = (bool) $enabled;
  bratonien_tools_save_watermark_engine_config($config);

  if ($enabled)
  {
    bratonien_tools_disable_piwigo_watermarks();
  }
}

function bratonien_tools_backup_piwigo_watermark_settings()
{
  bratonien_tools_load_derivative_params();

  $types = array();
  foreach (ImageStdParams::get_defined_type_map() as $type => $params)
  {
    $types[$type] = array(
      'use_watermark' => (bool) $params->use_watermark,
    );
  }

  return array(
    'types' => $types,
    'watermark' => bratonien_tools_watermark_params_to_array(ImageStdParams::get_watermark()),
  );
}

function bratonien_tools_restore_piwigo_watermark_settings($backup)
{
  if (!is_array($backup))
  {
    return;
  }

  bratonien_tools_load_derivative_params();

  // Backwards compatibility with backups produced before 0.3.1.
  $types = isset($backup['types']) && is_array($backup['types']) ? $backup['types'] : $backup;
  if (isset($backup['watermark']) && is_array($backup['watermark']))
  {
    ImageStdParams::set_watermark(bratonien_tools_array_to_watermark_params($backup['watermark']));
  }

  foreach (ImageStdParams::get_defined_type_map() as $type => $params)
  {
    if (isset($types[$type]['use_watermark']))
    {
      $params->use_watermark = (bool) $types[$type]['use_watermark'];
    }
  }

  ImageStdParams::save();
}

function bratonien_tools_disable_piwigo_watermarks()
{
  bratonien_tools_load_derivative_params();

  // Capture/migrate the current base watermark before the native Piwigo file
  // is cleared. This makes upgrades from 0.3.0 lossless.
  bratonien_tools_get_base_watermark_config();

  $current = ImageStdParams::get_watermark();
  $needs_save = !empty($current->file);

  if (!$needs_save)
  {
    foreach (ImageStdParams::get_defined_type_map() as $params)
    {
      if (!empty($params->use_watermark))
      {
        $needs_save = true;
        break;
      }
    }
  }

  if (!$needs_save)
  {
    return;
  }

  // Empty file is essential: ImageStdParams::get_custom() calls apply_global()
  // and would otherwise re-enable the native watermark for custom derivatives.
  $neutral = new WatermarkParams();
  $neutral->file = '';
  ImageStdParams::set_watermark($neutral);

  foreach (ImageStdParams::get_defined_type_map() as $params)
  {
    $params->use_watermark = false;
  }

  ImageStdParams::save();
}
