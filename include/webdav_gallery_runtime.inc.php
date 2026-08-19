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

function bratonien_tools_webdav_ondemand_derivative_url($image_id, $params)
{
  $image_id = (int)$image_id;
  if ($image_id < 1 || !is_object($params)) return null;

  $type = trim((string)($params->type ?? ''));
  if ($type === '') return null;

  return get_root_url().'plugins/'.BRATONIEN_TOOLS_ID.'/webdav-derivative.php?id='.$image_id.'&type='.rawurlencode($type);
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

  // Auf der Picture-/Fotorama-Seite wird nicht mehr waehrend des HTML-Aufbaus
  // synchron erzeugt. Fotorama arbeitet lazy: Erst wenn das konkrete Bild in
  // den Fokus kommt und seine URL abruft, erzeugt dieser Endpoint genau dieses
  // angeforderte Standard-Derivat aus dem bereits gecachten NC-Preview.
  $script_name = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
  if ($script_name === 'picture.php')
  {
    $ondemand_url = bratonien_tools_webdav_ondemand_derivative_url($image_id, $params);
    if ($ondemand_url) return $ondemand_url;
  }

  // Galerie-Fallback: niemals Connector-Platzhalter ausliefern. Solange kein
  // lokales korrektes Derivat vorliegt, wird das echte vorbereitete NC-Preview
  // verwendet. Der eigentliche Galerie-Derivattyp wird beim Sync vorgebaut.
  $preview_url = bratonien_tools_webdav_image_url($image_id, true);
  return $preview_url ?: $url;
}
