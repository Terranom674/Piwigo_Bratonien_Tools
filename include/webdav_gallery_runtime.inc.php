<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_webdav_derivative_matches_preview($derivative_path, $params, $image_id)
{
  $derivative_path = (string)$derivative_path;
  $image_id = (int)$image_id;
  if ($derivative_path === '' || $image_id < 1 || !is_file($derivative_path) || !is_readable($derivative_path)) return false;

  $info = bratonien_tools_webdav_image_source_info($image_id);
  if (!$info) return false;

  $preview = bratonien_tools_webdav_preview_path($info);
  if (!$preview || !is_file($preview) || !is_readable($preview)) return false;

  $preview_size = @getimagesize($preview);
  $derivative_size = @getimagesize($derivative_path);
  if (!is_array($preview_size) || empty($preview_size[0]) || empty($preview_size[1])) return false;
  if (!is_array($derivative_size) || empty($derivative_size[0]) || empty($derivative_size[1])) return false;

  try
  {
    $expected_size = $params->compute_final_size(array((int)$preview_size[0], (int)$preview_size[1]));
  }
  catch (Throwable $e)
  {
    return false;
  }

  if (!is_array($expected_size) || !isset($expected_size[0], $expected_size[1])) return false;
  if ((int)$derivative_size[0] !== (int)$expected_size[0] || (int)$derivative_size[1] !== (int)$expected_size[1]) return false;

  $preview_mtime = @filemtime($preview) ?: 0;
  $derivative_mtime = @filemtime($derivative_path) ?: 0;
  if ($preview_mtime > 0 && $derivative_mtime < $preview_mtime) return false;

  return true;
}

function bratonien_tools_filter_webdav_gallery_derivative_url($url, $params, $src_image, $rel_url)
{
  if (!is_object($src_image) || empty($src_image->id) || empty($src_image->rel_path)) return $url;

  $source_path = str_replace('\\', '/', (string)$src_image->rel_path);
  if (!preg_match('#/nc-webdav-source/connection-[0-9]+/root-[0-9]+/.+$#', $source_path))
  {
    return $url;
  }

  $image_id = (int)$src_image->id;
  $derivative_path = '';
  try
  {
    $derivative = new DerivativeImage($params, $src_image);
    if (!$derivative->same_as_source())
    {
      $derivative_path = $derivative->get_path();
      if (bratonien_tools_webdav_derivative_matches_preview($derivative_path, $params, $image_id))
      {
        return $url;
      }
    }
  }
  catch (Throwable $e)
  {
    error_log('Bratonien WebDAV derivative lookup #'.$image_id.': '.$e->getMessage());
  }

  $script_name = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
  if ($script_name === 'picture.php' && function_exists('bratonien_tools_webdav_generate_derivative'))
  {
    if ($derivative_path !== '' && is_file($derivative_path) && !bratonien_tools_webdav_derivative_matches_preview($derivative_path, $params, $image_id))
    {
      @unlink($derivative_path);
      clearstatcache(true, $derivative_path);
    }

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
        if (bratonien_tools_webdav_derivative_matches_preview($derivative_path, $params, $image_id))
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
      error_log('Bratonien WebDAV picture derivative #'.$image_id.': '.$detail);
    }
  }

  // Connector-Platzhalter oder daraus entstandene Derivate werden nie ausgeliefert.
  // Wenn noch kein echtes lokales Derivat bereitsteht, geht der Request immer auf
  // die echte WebDAV-Bildquelle bzw. deren vorbereitetes Preview.
  $preview_url = bratonien_tools_webdav_image_url($image_id, true);
  return $preview_url ?: $url;
}
