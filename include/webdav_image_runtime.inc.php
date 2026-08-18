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

  $result = pwg_query('SELECT path FROM '.IMAGES_TABLE.' WHERE id='.$image_id.' LIMIT 1');
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
  if (!preg_match('#/nc-webdav-source/connection-([0-9]+)/#', $normalized, $match))
  {
    return $cache[$image_id] = null;
  }

  $connection_id = (int)$match[1];
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

  $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
  if ($state_dir === '') return $cache[$image_id] = null;
  $mapping_file = $state_dir.'/webdav-map.json';
  if (!is_readable($mapping_file)) return $cache[$image_id] = null;
  $mapping = json_decode((string)file_get_contents($mapping_file), true);
  if (!is_array($mapping) || !isset($mapping['files']) || !is_array($mapping['files']))
  {
    return $cache[$image_id] = null;
  }

  $entry = $mapping['files'][$resolved] ?? $mapping['files'][$normalized] ?? null;
  if (!is_array($entry) || (string)($entry['kind'] ?? '') !== 'file')
  {
    return $cache[$image_id] = null;
  }

  return $cache[$image_id] = array(
    'image_id'=>$image_id,
    'connection_id'=>$connection_id,
    'webdav_path'=>(string)($entry['webdav_path'] ?? ''),
    'content_type'=>(string)($entry['content_type'] ?? ''),
    'size'=>(int)($entry['size'] ?? 0),
    'etag'=>(string)($entry['etag'] ?? ''),
  );
}

function bratonien_tools_webdav_image_url($image_id)
{
  $info = bratonien_tools_webdav_image_source_info($image_id);
  if (!$info) return null;
  $url = get_root_url().'plugins/'.BRATONIEN_TOOLS_ID.'/webdav-image.php?id='.(int)$image_id;
  if ($info['etag'] !== '') $url .= '&v='.rawurlencode(substr(sha1($info['etag']), 0, 12));
  return $url;
}

function bratonien_tools_filter_webdav_src_url($url, $src_image)
{
  if (!is_object($src_image) || empty($src_image->id)) return $url;
  $webdav_url = bratonien_tools_webdav_image_url((int)$src_image->id);
  return $webdav_url ?: $url;
}

function bratonien_tools_filter_webdav_derivative_url($url, $params, $src_image, $rel_url)
{
  if (!is_object($src_image) || empty($src_image->id)) return $url;
  $webdav_url = bratonien_tools_webdav_image_url((int)$src_image->id);
  return $webdav_url ?: $url;
}
