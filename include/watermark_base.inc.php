<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_load_derivative_params()
{
  if (!class_exists('ImageStdParams'))
  {
    require_once(PHPWG_ROOT_PATH . 'include/derivative_std_params.inc.php');
  }
}

function bratonien_tools_watermark_params_to_array($watermark)
{
  return array(
    'file' => (string)($watermark->file ?? ''),
    'xpos' => (int)($watermark->xpos ?? 50),
    'ypos' => (int)($watermark->ypos ?? 50),
    'xrepeat' => (int)($watermark->xrepeat ?? 0),
    'yrepeat' => (int)($watermark->yrepeat ?? 0),
    'opacity' => (int)($watermark->opacity ?? 100),
    'minw' => isset($watermark->min_size[0]) ? (int)$watermark->min_size[0] : 500,
    'minh' => isset($watermark->min_size[1]) ? (int)$watermark->min_size[1] : 500,
  );
}

function bratonien_tools_array_to_watermark_params(array $data)
{
  bratonien_tools_load_derivative_params();

  $watermark = new WatermarkParams();
  $watermark->file = (string)($data['file'] ?? '');
  $watermark->xpos = (int)($data['xpos'] ?? 50);
  $watermark->ypos = (int)($data['ypos'] ?? 50);
  $watermark->xrepeat = (int)($data['xrepeat'] ?? 0);
  $watermark->yrepeat = (int)($data['yrepeat'] ?? 0);
  $watermark->opacity = (int)($data['opacity'] ?? 100);
  $watermark->min_size = array(
    (int)($data['minw'] ?? 500),
    (int)($data['minh'] ?? 500),
  );
  return $watermark;
}

function bratonien_tools_default_base_watermark_config()
{
  return array(
    'file' => '',
    'xpos' => 90,
    'ypos' => 90,
    'xrepeat' => 0,
    'yrepeat' => 0,
    'opacity' => 35,
    'minw' => 10,
    'minh' => 10,
    'scale_percent' => 100.0,
  );
}

function bratonien_tools_save_base_watermark_config(array $config)
{
  if (!function_exists('conf_update_param'))
  {
    throw new RuntimeException('Piwigo-Konfiguration ist nicht verfuegbar.');
  }

  $config = array_merge(bratonien_tools_default_base_watermark_config(), $config);
  $config['scale_percent'] = max(1.0, min(1000.0, (float)$config['scale_percent']));
  conf_update_param('bratonien_watermark_base', json_encode($config));
  // Keep the old single-value setting in sync for compatibility with 0.3.0.
  conf_update_param('bratonien_watermark_base_scale_percent', (string)$config['scale_percent']);
}

function bratonien_tools_get_base_watermark_config()
{
  $default = bratonien_tools_default_base_watermark_config();
  if (!function_exists('conf_get_param'))
  {
    return $default;
  }

  $stored = conf_get_param('bratonien_watermark_base', null);
  if (!empty($stored))
  {
    $decoded = json_decode($stored, true);
    if (is_array($decoded))
    {
      return array_merge($default, $decoded);
    }
  }

  // Migration from the previous implementation: before 0.3.1 the base
  // watermark was stored directly in Piwigo's native watermark parameters.
  bratonien_tools_load_derivative_params();
  $native = bratonien_tools_watermark_params_to_array(ImageStdParams::get_watermark());
  $native['scale_percent'] = (float)conf_get_param('bratonien_watermark_base_scale_percent', 100);
  $config = array_merge($default, $native);
  bratonien_tools_save_base_watermark_config($config);
  return $config;
}
