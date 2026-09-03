<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_webdav_materialize_source_info($image_id)
{
  static $cache = array();

  $image_id = (int)$image_id;
  if ($image_id < 1) return null;
  $warmup_cli = PHP_SAPI === 'cli'
    && strpos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'Bratonien-WebDAV-Cache-Warmup/') === 0;
  if (!$warmup_cli && array_key_exists($image_id, $cache)) return $cache[$image_id];

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
  if ($connection_id < 1 || $root_fileid < 1 || $relative_path === '')
  {
    return $cache[$image_id] = null;
  }

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

  $root_found = false;
  $root_path = '';
  $roots = isset($config['roots']) && is_array($config['roots']) ? $config['roots'] : array();
  foreach ($roots as $root)
  {
    if ((int)($root['fileid'] ?? 0) === $root_fileid)
    {
      $root_found = true;
      $root_path = trim((string)($root['webdav_path'] ?? ''), '/');
      break;
    }
  }
  if (!$root_found) return $cache[$image_id] = null;

  $webdav_path = trim($root_path === '' ? $relative_path : $root_path.'/'.$relative_path, '/');
  if ($webdav_path === '') return $cache[$image_id] = null;

  $content_type = '';
  $size = 0;
  $etag = '';
  $fileid = 0;
  $width = 0;
  $height = 0;
  $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
  if ($state_dir !== '')
  {
    $mapping_file = $state_dir.'/webdav-map.json';
    if (is_readable($mapping_file))
    {
      $mapping = json_decode((string)file_get_contents($mapping_file), true);
      if (is_array($mapping) && isset($mapping['files']) && is_array($mapping['files']))
      {
        $files = $mapping['files'];
        $entry = $files[$resolved] ?? $files[$normalized] ?? null;
        if (!is_array($entry))
        {
          foreach ($files as $candidate)
          {
            if (!is_array($candidate) || (string)($candidate['kind'] ?? '') !== 'file') continue;
            if (trim((string)($candidate['webdav_path'] ?? ''), '/') !== $webdav_path) continue;
            $entry = $candidate;
            break;
          }
        }
        if (is_array($entry) && (string)($entry['kind'] ?? '') === 'file')
        {
          $mapped_path = trim((string)($entry['webdav_path'] ?? ''), '/');
          if ($mapped_path !== '') $webdav_path = $mapped_path;
          $content_type = (string)($entry['content_type'] ?? '');
          $size = (int)($entry['size'] ?? 0);
          $etag = (string)($entry['etag'] ?? '');
          $fileid = (int)($entry['fileid'] ?? 0);
          $width = (int)($entry['width'] ?? 0);
          $height = (int)($entry['height'] ?? 0);
        }
      }
    }
  }

  return $cache[$image_id] = array(
    'image_id'=>$image_id,
    'connection_id'=>$connection_id,
    'root_fileid'=>$root_fileid,
    'relative_path'=>$relative_path,
    'webdav_path'=>$webdav_path,
    'content_type'=>$content_type,
    'size'=>$size,
    'etag'=>$etag,
    'fileid'=>$fileid,
    'width'=>$width,
    'height'=>$height,
    'state_dir'=>$state_dir,
    'coi'=>$row['coi'] ?? null,
  );
}

function bratonien_tools_webdav_materialize_image_url($image_id)
{
  $info = bratonien_tools_webdav_materialize_source_info($image_id);
  if (!$info) return null;

  $url = get_root_url().'plugins/'.BRATONIEN_TOOLS_ID.'/webdav-image.php?id='.(int)$image_id;
  if ($info['etag'] !== '') $url .= '&v='.rawurlencode(substr(sha1($info['etag']), 0, 12));
  return $url;
}

function bratonien_tools_webdav_materialize_custom_params($key)
{
  if (!class_exists('DerivativeParams') || !class_exists('SizingParams')) return null;

  $tokens = explode('_', trim((string)$key));
  if (!$tokens || $tokens[0] === '') return null;

  $parse_size = function($value)
  {
    if (!preg_match('/^[0-9]+(?:x[0-9]+)?$/', (string)$value)) return null;
    $parts = explode('x', (string)$value, 2);
    $width = (int)$parts[0];
    $height = count($parts) === 1 ? $width : (int)$parts[1];
    if ($width < 1 || $height < 1 || $width > 20000 || $height > 20000) return null;
    return array($width, $height);
  };

  $token = array_shift($tokens);
  $crop = 0;
  $min_size = null;

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
    $crop_token = (string)array_shift($tokens);
    $min_size = $parse_size(array_shift($tokens));
    if (!preg_match('/^[a-z]$/', $crop_token) || $min_size === null) return null;
    $crop = function_exists('char_to_fraction') ? char_to_fraction($crop_token) : 0;
  }

  if ($size === null) return null;

  $params = new DerivativeParams(new SizingParams($size, $crop, $min_size));
  if (class_exists('ImageStdParams')) ImageStdParams::apply_global($params);
  return $params;
}

function bratonien_tools_webdav_materialize_variant_from_params($params)
{
  if (!is_object($params) || !class_exists('ImageStdParams')) return null;

  $type = (string)($params->type ?? '');
  $defined = ImageStdParams::get_defined_type_map();
  if ($type !== '' && defined('IMG_CUSTOM') && $type !== IMG_CUSTOM && isset($defined[$type]))
  {
    return 'standard:'.$type;
  }

  if (!method_exists($params, 'add_url_tokens')) return null;
  $tokens = array();
  $params->add_url_tokens($tokens);
  if (!$tokens) return null;

  $key = implode('_', $tokens);
  if (!preg_match('/^[A-Za-z0-9_x-]+$/', $key)) return null;
  return 'custom:'.$key;
}

function bratonien_tools_webdav_materialize_params_from_variant($variant)
{
  $variant = trim((string)$variant);
  if ($variant === '' || !class_exists('ImageStdParams')) return null;

  if (strpos($variant, 'standard:') === 0)
  {
    $type = substr($variant, strlen('standard:'));
    $defined = ImageStdParams::get_defined_type_map();
    return isset($defined[$type]) ? $defined[$type] : null;
  }

  if (strpos($variant, 'custom:') === 0)
  {
    $key = substr($variant, strlen('custom:'));
    if (!preg_match('/^[A-Za-z0-9_x-]+$/', $key)) return null;
    return bratonien_tools_webdav_materialize_custom_params($key);
  }

  return null;
}

function bratonien_tools_webdav_materialize_b64url_encode($value)
{
  return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
}

function bratonien_tools_webdav_materialize_after_signature($image_id, $variant, $after_url)
{
  global $conf;
  $key = !empty($conf['secret_key']) ? $conf['secret_key'] : ($conf['db_password'] ?? 'bratonien-tools');
  return hash_hmac('sha256', (int)$image_id.'|'.(string)$variant.'|'.(string)$after_url, $key);
}

function bratonien_tools_webdav_materialize_derivative_url($image_id, $variant, array $info, $after_url='')
{
  $url = get_root_url().'plugins/'.BRATONIEN_TOOLS_ID.'/webdav-derivative-guard.php?id='.(int)$image_id.'&variant='.rawurlencode($variant);
  if ($after_url !== '')
  {
    $url .= '&after='.rawurlencode(bratonien_tools_webdav_materialize_b64url_encode($after_url));
    $url .= '&sig='.rawurlencode(bratonien_tools_webdav_materialize_after_signature($image_id, $variant, $after_url));
  }
  if (!empty($info['etag'])) $url .= '&v='.rawurlencode(substr(sha1((string)$info['etag']), 0, 12));
  return $url;
}

function bratonien_tools_filter_webdav_materialize_src_url($url, $src_image)
{
  if (!is_object($src_image) || empty($src_image->id)) return $url;
  $webdav_url = bratonien_tools_webdav_materialize_image_url((int)$src_image->id);
  return $webdav_url ?: $url;
}

function bratonien_tools_filter_webdav_materialize_derivative_url($url, $params, $src_image, $rel_url)
{
  if (!is_object($src_image) || empty($src_image->id)) return $url;

  $image_id = (int)$src_image->id;
  $info = bratonien_tools_webdav_materialize_source_info($image_id);
  if (!$info) return $url;

  try
  {
    $derivative = new DerivativeImage($params, $src_image);
    if ($derivative->same_as_source())
    {
      $source_url = bratonien_tools_webdav_materialize_image_url($image_id);
      return $source_url ?: $url;
    }

    $path = $derivative->get_path();
    if ($path !== '' && is_file($path) && is_readable($path)) return $url;

    $variant = bratonien_tools_webdav_materialize_variant_from_params($params);
    if ($variant === null) return $url;

    return bratonien_tools_webdav_materialize_derivative_url($image_id, $variant, $info, (string)$url);
  }
  catch (Throwable $e)
  {
    error_log('Bratonien WebDAV materialize derivative #'.$image_id.': '.$e->getMessage());
    return $url;
  }
}
