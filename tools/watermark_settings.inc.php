<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_watermark_cache_dir()
{
  return PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR.'bratonien-watermark';
}

function bratonien_tools_remove_tree($path)
{
  $path = rtrim((string)$path, DIRECTORY_SEPARATOR);
  if ($path === '' || !file_exists($path)) return;

  if (is_file($path) || is_link($path))
  {
    @unlink($path);
    return;
  }

  foreach (scandir($path) ?: array() as $entry)
  {
    if ($entry === '.' || $entry === '..') continue;
    bratonien_tools_remove_tree($path.DIRECTORY_SEPARATOR.$entry);
  }
  @rmdir($path);
}

function bratonien_tools_clear_watermark_cache()
{
  $dir = bratonien_tools_watermark_cache_dir();
  $derivative_root = realpath(PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR);
  $parent = realpath(dirname($dir));

  if ($derivative_root === false || $parent === false || $parent !== $derivative_root)
  {
    throw new RuntimeException('Wasserzeichen-Cachepfad ist ungueltig.');
  }

  if (is_dir($dir))
  {
    bratonien_tools_remove_tree($dir);
  }
}

function bratonien_tools_watermark_cache_upgrade()
{
  $version = '0.9.7.13';
  if ((string)conf_get_param('bratonien_watermark_cache_version', '') === $version)
  {
    return;
  }

  bratonien_tools_clear_watermark_cache();
  conf_update_param('bratonien_watermark_cache_version', $version);
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
  bratonien_tools_clear_watermark_cache();

  return array('message'=>'Globale Wasserzeichenregeln gespeichert.');
}
