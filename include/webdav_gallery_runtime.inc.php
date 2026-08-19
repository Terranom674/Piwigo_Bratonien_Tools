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

  $derivative_path = '';
  try
  {
    $derivative = new DerivativeImage($params, $src_image);
    if (!$derivative->same_as_source())
    {
      $derivative_path = $derivative->get_path();
      if ($derivative_path !== '' && is_file($derivative_path) && is_readable($derivative_path))
      {
        return $url;
      }
    }
  }
  catch (Throwable $e)
  {
    error_log('Bratonien WebDAV derivative lookup #'.(int)$src_image->id.': '.$e->getMessage());
  }

  $script_name = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
  if ($script_name === 'picture.php' && function_exists('bratonien_tools_webdav_generate_derivative'))
  {
    $detail = '';
    try
    {
      if (bratonien_tools_webdav_generate_derivative($params, $src_image, $detail))
      {
        if ($derivative_path === '')
        {
          $probe = new DerivativeImage($params, $src_image);
          if (!$probe->same_as_source()) $derivative_path = $probe->get_path();
        }
        if ($derivative_path !== '' && is_file($derivative_path) && is_readable($derivative_path))
        {
          return $url;
        }
      }
    }
    catch (Throwable $e)
    {
      $detail = get_class($e).': '.$e->getMessage();
    }

    if ($detail !== '')
    {
      error_log('Bratonien WebDAV picture derivative #'.(int)$src_image->id.': '.$detail);
    }
  }

  $preview_url = bratonien_tools_webdav_image_url((int)$src_image->id, true);
  return $preview_url ?: $url;
}
