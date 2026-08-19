<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_filter_webdav_gallery_derivative_url($url, $params, $src_image, $rel_url)
{
  if (!is_object($src_image) || empty($src_image->id) || empty($src_image->rel_path)) return $url;

  $source_path = str_replace('\\', '/', (string)$src_image->rel_path);
  if (!preg_match('#/nc-webdav-source/connection-[0-9]+/root-[0-9]+/.+$#', $source_path))
  {
    return $url;
  }

  try
  {
    $derivative = new DerivativeImage($params, $src_image);
    if (!$derivative->same_as_source())
    {
      $path = $derivative->get_path();
      if ($path !== '' && is_file($path) && is_readable($path))
      {
        return $url;
      }
    }
  }
  catch (Throwable $e)
  {
    error_log('Bratonien WebDAV gallery derivative lookup #'.(int)$src_image->id.': '.$e->getMessage());
  }

  $preview_url = bratonien_tools_webdav_image_url((int)$src_image->id, true);
  return $preview_url ?: $url;
}
