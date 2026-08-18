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
  $roots = isset($config['roots']) && is_array($config['roots']) ? $config['roots'] : array();
  foreach ($roots as $root)
  {
    if ((int)($root['fileid'] ?? 0) === $root_fileid)
    {
      $root_path = trim((string)($root['webdav_path'] ?? ''), '/');
      break;
    }
  }
  if ($root_path === '') return $cache[$image_id] = null;

  $webdav_path = $root_path.'/'.$relative_path;
  $content_type = '';
  $size = 0;
  $etag = '';

  // Metadata is optional. Routing must not depend on the root-owned runtime map.
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
  $path = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-webdav-preview/connection-'.$connection_id.'/'.sha1($webdav_path).'.webp';
  return is_file($path) && is_readable($path) ? $path : null;
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

function bratonien_tools_webdav_generate_derivative($params, $src_image)
{
  if (!is_object($src_image) || empty($src_image->id)) return false;
  $info = bratonien_tools_webdav_image_source_info((int)$src_image->id);
  if (!$info) return false;
  $preview = bratonien_tools_webdav_preview_path($info);
  if (!$preview) return false;

  if (!class_exists('DerivativeImage')) require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');
  if (!class_exists('pwg_image')) require_once(PHPWG_ROOT_PATH.'admin/include/image.class.php');

  $derivative = new DerivativeImage($params, $src_image);
  $target = $derivative->get_path();
  if ($target === '' || strpos($target, PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR) !== 0) return false;

  $preview_mtime = @filemtime($preview) ?: 0;
  if (is_file($target) && is_readable($target) && (@filemtime($target) ?: 0) >= $preview_mtime)
  {
    return true;
  }

  $directory = dirname($target);
  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) return false;

  $image = new pwg_image($preview);
  try
  {
    $original_size = array($image->get_width(), $image->get_height());
    $crop_rect = null;
    $scaled_size = null;
    $params->sizing->compute($original_size, $info['coi'] ?? null, $crop_rect, $scaled_size);
    if ($crop_rect)
    {
      $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
    }
    if ($scaled_size)
    {
      $image->resize($scaled_size[0], $scaled_size[1]);
    }
    if (!empty($params->sharpen))
    {
      $image->sharpen($params->sharpen);
    }
    $image->write($target);
  }
  finally
  {
    $image->destroy();
  }

  @chmod($target, 0644);
  clearstatcache(true, $target);
  return is_file($target) && is_readable($target);
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

  try
  {
    if (bratonien_tools_webdav_generate_derivative($params, $src_image))
    {
      // Keep Piwigo's normal derivative URL. The file behind it now contains
      // the derivative generated from the prepared WebDAV preview.
      return $url;
    }
  }
  catch (Throwable $e)
  {
    error_log('Bratonien WebDAV derivative #'.(int)$src_image->id.': '.$e->getMessage());
  }

  // Last-resort display fallback: show the prepared preview/original stream
  // instead of allowing Piwigo to derive from the 1x1 placeholder.
  $webdav_url = bratonien_tools_webdav_image_url((int)$src_image->id, true);
  return $webdav_url ?: $url;
}
