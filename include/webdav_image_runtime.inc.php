<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_webdav_image_source_info($image_id)
{
  static $cache = array();
  $image_id = (int)$image_id;
  if ($image_id < 1) return null;
  if (array_key_exists($image_id, $cache)) return $cache[$image_id];

  $result = pwg_query('SELECT path, coi FROM '.IMAGES_TABLE.' WHERE id='.$image_id.' LIMIT 1');
  if (!pwg_db_num_rows($result)) return $cache[$image_id] = null;
  $row = pwg_db_fetch_assoc($result);
  $path = (string)($row['path'] ?? '');
  if ($path === '') return $cache[$image_id] = null;

  $absolute = $path;
  if (strpos($absolute, '/') !== 0)
  {
    $absolute = PHPWG_ROOT_PATH.ltrim(preg_replace('#^\./#', '', $absolute), '/');
  }
  $resolved = realpath($absolute);
  if ($resolved === false) return $cache[$image_id] = null;

  $normalized = str_replace('\\', '/', $resolved);
  if (!preg_match('#/nc-webdav-source/connection-([0-9]+)/root-([0-9]+)/(.*)$#', $normalized, $match))
  {
    return $cache[$image_id] = null;
  }

  $connection_id = (int)$match[1];
  $root_fileid = (int)$match[2];
  $relative_path = trim((string)$match[3], '/');
  if ($relative_path === '') return $cache[$image_id] = null;

  $table = defined('BRATONIEN_TOOLS_NC_CONNECTIONS_TABLE')
    ? BRATONIEN_TOOLS_NC_CONNECTIONS_TABLE
    : $GLOBALS['prefixeTable'].'bratonien_tools_nc_connections';
  $connection_result = pwg_query('SELECT config_json FROM `'.$table.'` WHERE id='.$connection_id.' LIMIT 1');
  if (!pwg_db_num_rows($connection_result)) return $cache[$image_id] = null;
  $connection_row = pwg_db_fetch_assoc($connection_result);
  $config = json_decode((string)$connection_row['config_json'], true);
  if (!is_array($config) || (string)($config['source_mode'] ?? '') !== 'webdav-placeholder')
  {
    return $cache[$image_id] = null;
  }

  $root_path = '';
  $root_found = false;
  $roots = isset($config['roots']) && is_array($config['roots']) ? $config['roots'] : array();
  foreach ($roots as $root)
  {
    if ((int)($root['fileid'] ?? 0) === $root_fileid)
    {
      $root_path = trim((string)($root['webdav_path'] ?? ''), '/');
      $root_found = true;
      break;
    }
  }
  if (!$root_found) return $cache[$image_id] = null;

  $root_is_base = $root_path === '';
  $webdav_path = $root_is_base ? $relative_path : $root_path.'/'.$relative_path;
  $content_type = '';
  $size = 0;
  $etag = '';

  $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
  if ($state_dir !== '')
  {
    $mapping_file = $state_dir.'/webdav-map.json';
    if (is_readable($mapping_file))
    {
      $mapping = json_decode((string)file_get_contents($mapping_file), true);
      if (is_array($mapping) && isset($mapping['files']) && is_array($mapping['files']))
      {
        $entry = $mapping['files'][$resolved] ?? $mapping['files'][$normalized] ?? null;
        if (is_array($entry) && (string)($entry['kind'] ?? '') === 'file')
        {
          $webdav_path = trim((string)($entry['webdav_path'] ?? $webdav_path), '/');
          $content_type = (string)($entry['content_type'] ?? '');
          $size = (int)($entry['size'] ?? 0);
          $etag = (string)($entry['etag'] ?? '');
        }
      }
    }
  }

  return $cache[$image_id] = array(
    'image_id'=>$image_id,
    'connection_id'=>$connection_id,
    'webdav_path'=>$webdav_path,
    'root_is_base'=>$root_is_base,
    'content_type'=>$content_type,
    'size'=>$size,
    'etag'=>$etag,
    'coi'=>$row['coi'] ?? null,
  );
}

function bratonien_tools_webdav_image_url($image_id, $preview=false)
{
  $info = bratonien_tools_webdav_image_source_info($image_id);
  if (!$info) return null;

  $url = get_root_url().'plugins/'.BRATONIEN_TOOLS_ID.'/webdav-image.php?id='.(int)$image_id;
  if ($preview) $url .= '&preview=1';
  if ($info['etag'] !== '') $url .= '&v='.rawurlencode(substr(sha1($info['etag']), 0, 12));
  return $url;
}

function bratonien_tools_webdav_preview_path(array $info)
{
  $connection_id = (int)($info['connection_id'] ?? 0);
  $webdav_path = trim((string)($info['webdav_path'] ?? ''), '/');
  if ($connection_id < 1 || $webdav_path === '') return null;

  $base = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-webdav-preview/connection-'.$connection_id.'/'.sha1($webdav_path);
  foreach (array('jpg', 'png', 'webp') as $ext)
  {
    $path = $base.'.'.$ext;
    if (is_file($path) && is_readable($path)) return $path;
  }
  return null;
}

function bratonien_tools_webdav_preview_content_type($path)
{
  $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
  if ($ext === 'png') return 'image/png';
  if ($ext === 'webp') return 'image/webp';
  return 'image/jpeg';
}

function bratonien_tools_webdav_custom_derivative_params($key)
{
  if (!class_exists('DerivativeParams') || !class_exists('SizingParams')) return null;
  $tokens = explode('_', (string)$key);
  if (!$tokens) return null;

  $token = array_shift($tokens);
  $crop = 0;
  $min_size = null;
  $parse_size = function($value)
  {
    $parts = explode('x', (string)$value, 2);
    if (count($parts) === 1)
    {
      $size = max(1, (int)$parts[0]);
      return array($size, $size);
    }
    return array(max(1, (int)$parts[0]), max(1, (int)$parts[1]));
  };

  if (isset($token[0]) && $token[0] === 's')
  {
    $size = $parse_size(substr($token, 1));
  }
  elseif (isset($token[0]) && $token[0] === 'e')
  {
    $crop = 1;
    $size = $min_size = $parse_size(substr($token, 1));
  }
  else
  {
    if (count($tokens) < 2) return null;
    $size = $parse_size($token);
    $crop_token = array_shift($tokens);
    $min_size = $parse_size(array_shift($tokens));
    $crop = function_exists('char_to_fraction') ? char_to_fraction($crop_token) : 0;
  }

  $params = new DerivativeParams(new SizingParams($size, $crop, $min_size));
  if (class_exists('ImageStdParams')) ImageStdParams::apply_global($params);
  return $params;
}

function bratonien_tools_webdav_derivative_variants()
{
  $variants = array();
  if (!class_exists('ImageStdParams')) return $variants;

  foreach (ImageStdParams::get_defined_type_map() as $type => $params)
  {
    $variants['standard:'.$type] = $params;
  }
  foreach (ImageStdParams::$custom as $custom_key => $last_used)
  {
    $params = bratonien_tools_webdav_custom_derivative_params($custom_key);
    if ($params) $variants['custom:'.$custom_key] = $params;
  }
  return $variants;
}

function bratonien_tools_webdav_generate_derivative($params, $src_image, &$detail=null)
{
  global $conf;

  $detail = '';
  if (!is_object($src_image) || empty($src_image->id))
  {
    $detail = 'SrcImage fehlt.';
    return false;
  }

  $info = bratonien_tools_webdav_image_source_info((int)$src_image->id);
  if (!$info)
  {
    $detail = 'WebDAV-Quellzuordnung fehlt.';
    return false;
  }

  $preview = bratonien_tools_webdav_preview_path($info);
  if (!$preview)
  {
    $detail = 'Vorbereitetes WebDAV-Preview fehlt.';
    return false;
  }

  $preview_ext = strtolower(pathinfo($preview, PATHINFO_EXTENSION));
  if (!in_array($preview_ext, array('jpg', 'jpeg', 'png', 'gif'), true))
  {
    $detail = 'Preview-Format '.$preview_ext.' ist für die Piwigo-Bildbibliothek nicht sicher nutzbar.';
    return false;
  }

  if (!class_exists('DerivativeImage')) require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');
  if (!class_exists('pwg_image')) require_once(PHPWG_ROOT_PATH.'admin/include/image.class.php');

  $derivative = new DerivativeImage($params, $src_image);
  if ($derivative->same_as_source())
  {
    $detail = 'Identisch mit Quelle; kein eigenes Derivat erforderlich.';
    return true;
  }

  $target = $derivative->get_path();
  $derivative_root = PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR;
  if ($target === '' || strpos($target, $derivative_root) !== 0)
  {
    $detail = 'Ungültiger Derivat-Zielpfad: '.$target;
    return false;
  }

  $preview_mtime = @filemtime($preview) ?: 0;
  if (is_file($target) && is_readable($target) && (@filemtime($target) ?: 0) >= max($preview_mtime, (int)($params->last_mod_time ?? 0)))
  {
    $detail = 'Bereits vorhanden.';
    return true;
  }

  $directory = dirname($target);
  $dir_ok = function_exists('mkgetdir') ? mkgetdir($directory) : (is_dir($directory) || @mkdir($directory, 0755, true));
  if (!$dir_ok || !is_dir($directory))
  {
    $detail = 'Derivat-Verzeichnis konnte nicht angelegt werden: '.$directory;
    return false;
  }

  $image = null;
  try
  {
    $image = new pwg_image($preview);
    $original_size = array((int)$image->get_width(), (int)$image->get_height());
    if ($original_size[0] < 1 || $original_size[1] < 1)
    {
      $detail = 'Preview-Abmessungen sind ungültig.';
      return false;
    }

    $crop_rect = null;
    $scaled_size = null;
    $params->sizing->compute($original_size, $info['coi'] ?? null, $crop_rect, $scaled_size);

    if ($crop_rect)
    {
      if (!$image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t))
      {
        $detail = 'Crop fehlgeschlagen.';
        return false;
      }
    }

    $final_size = $original_size;
    if ($crop_rect)
    {
      $final_size = array($crop_rect->width(), $crop_rect->height());
    }
    if ($scaled_size)
    {
      if (!$image->resize($scaled_size[0], $scaled_size[1]))
      {
        $detail = 'Resize fehlgeschlagen.';
        return false;
      }
      $final_size = array((int)$scaled_size[0], (int)$scaled_size[1]);
    }

    if (!empty($params->sharpen) && !$image->sharpen($params->sharpen))
    {
      $detail = 'Sharpen fehlgeschlagen.';
      return false;
    }

    if ($params->will_watermark($final_size))
    {
      $wm = ImageStdParams::get_watermark();
      if (!empty($wm->file))
      {
        $wm_path = PHPWG_ROOT_PATH.$wm->file;
        if (!is_file($wm_path) || !is_readable($wm_path))
        {
          $detail = 'Wasserzeichen-Datei fehlt: '.$wm_path;
          return false;
        }

        $wm_image = new pwg_image($wm_path);
        try
        {
          $wm_size = array((int)$wm_image->get_width(), (int)$wm_image->get_height());
          if ($final_size[0] < $wm_size[0] || $final_size[1] < $wm_size[1])
          {
            $wm_scaling = SizingParams::classic($final_size[0], $final_size[1]);
            $tmp = null;
            $wm_scaled = null;
            $wm_scaling->compute($wm_size, null, $tmp, $wm_scaled);
            if ($wm_scaled)
            {
              $wm_image->resize($wm_scaled[0], $wm_scaled[1]);
              $wm_size = array((int)$wm_scaled[0], (int)$wm_scaled[1]);
            }
          }

          $x = (int)round(($wm->xpos / 100) * ($final_size[0] - $wm_size[0]));
          $y = (int)round(($wm->ypos / 100) * ($final_size[1] - $wm_size[1]));
          $image->compose($wm_image, $x, $y, $wm->opacity);

          if ($wm->xrepeat || $wm->yrepeat)
          {
            $xpad = $wm_size[0] + max(30, (int)round($wm_size[0] / 4));
            $ypad = $wm_size[1] + max(30, (int)round($wm_size[1] / 4));
            for ($i = -$wm->xrepeat; $i <= $wm->xrepeat; $i++)
            {
              for ($j = -$wm->yrepeat; $j <= $wm->yrepeat; $j++)
              {
                if (!$i && !$j) continue;
                $x2 = $x + $i * $xpad;
                $y2 = $y + $j * $ypad;
                if ($x2 >= 0 && $x2 + $wm_size[0] < $final_size[0] && $y2 >= 0 && $y2 + $wm_size[1] < $final_size[1])
                {
                  $image->compose($wm_image, $x2, $y2, $wm->opacity);
                }
              }
            }
          }
        }
        finally
        {
          $wm_image->destroy();
        }
      }
    }

    if (isset($conf['derivatives_strip_metadata_threshold']) && $final_size[0] * $final_size[1] < (int)$conf['derivatives_strip_metadata_threshold'])
    {
      $image->strip();
    }

    $quality = (int)ImageStdParams::$quality;
    if (isset($params->type) && in_array($params->type, array(IMG_3XLARGE, IMG_4XLARGE), true))
    {
      $quality = min($quality, 75);
    }
    $image->set_compression_quality($quality);

    $written = $image->write($target);
    if ($written === false)
    {
      $detail = 'Bildbibliothek konnte das Derivat nicht schreiben.';
      return false;
    }
  }
  catch (Throwable $e)
  {
    $detail = get_class($e).': '.$e->getMessage();
    return false;
  }
  finally
  {
    if (is_object($image)) $image->destroy();
  }

  @chmod($target, 0644);
  clearstatcache(true, $target);
  $size = @getimagesize($target);
  if (!is_file($target) || !is_readable($target) || !is_array($size) || empty($size[0]) || empty($size[1]))
  {
    $detail = 'Derivatdatei wurde nicht gültig erzeugt: '.$target;
    return false;
  }

  $detail = 'Erzeugt: '.$target.' ('.$size[0].'x'.$size[1].').';
  return true;
}

function bratonien_tools_filter_webdav_src_url($url, $src_image)
{
  if (!is_object($src_image) || empty($src_image->id)) return $url;
  $webdav_url = bratonien_tools_webdav_image_url((int)$src_image->id, false);
  return $webdav_url ?: $url;
}

function bratonien_tools_filter_webdav_derivative_url($url, $params, $src_image, $rel_url)
{
  if (!is_object($src_image) || empty($src_image->id)) return $url;
  $info = bratonien_tools_webdav_image_source_info((int)$src_image->id);
  if (!$info) return $url;

  if (empty($info['root_is_base']))
  {
    try
    {
      $derivative = new DerivativeImage($params, $src_image);
      if (!$derivative->same_as_source())
      {
        $path = $derivative->get_path();
        if ($path !== '' && is_file($path) && is_readable($path)) return $url;
      }
    }
    catch (Throwable $e)
    {
      error_log('Bratonien WebDAV derivative lookup #'.(int)$src_image->id.': '.$e->getMessage());
    }
  }

  $preview_url = bratonien_tools_webdav_image_url((int)$src_image->id, true);
  return $preview_url ?: $url;
}
