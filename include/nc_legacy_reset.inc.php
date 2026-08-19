<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_legacy_remove_tree($path, $allowed_root)
{
  $path = rtrim(str_replace('\\', '/', (string)$path), '/');
  $allowed_root = rtrim(str_replace('\\', '/', (string)$allowed_root), '/');
  if ($path === '' || $allowed_root === '' || $path === $allowed_root || strpos($path, $allowed_root.'/') !== 0)
  {
    return 0;
  }
  if (!file_exists($path) && !is_link($path)) return 0;

  if (is_link($path) || is_file($path))
  {
    return @unlink($path) ? 1 : 0;
  }

  $removed = 0;
  $items = @scandir($path);
  if (is_array($items))
  {
    foreach ($items as $item)
    {
      if ($item === '.' || $item === '..') continue;
      $removed += bratonien_tools_nc_legacy_remove_tree($path.'/'.$item, $allowed_root);
    }
  }
  if (@rmdir($path)) $removed++;
  return $removed;
}

function bratonien_tools_nc_legacy_remove_state_files($state_root)
{
  $removed = 0;
  foreach (glob(rtrim($state_root, '/').'/connection-*') ?: array() as $connection_dir)
  {
    if (!is_dir($connection_dir)) continue;
    foreach (array('webdav-map.json', 'webdav-manifest.tsv', 'webdav-shadow-map.json') as $name)
    {
      $path = $connection_dir.'/'.$name;
      if (is_file($path) && @unlink($path)) $removed++;
    }
  }
  return $removed;
}

function bratonien_tools_nc_reset_legacy_imports()
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  $ids = array();
  $result = pwg_query(
    "SELECT id FROM ".IMAGES_TABLE.
    " WHERE path LIKE '%nc-webdav-source/connection-%'"
  );
  while ($row = pwg_db_fetch_assoc($result))
  {
    $ids[] = (int)$row['id'];
  }

  if ($ids)
  {
    delete_elements($ids, false);
  }

  $data_root = rtrim(PHPWG_ROOT_PATH, '/').'/_data';
  $tool_root = $data_root.'/bratonien-tools';
  $removed_files = 0;
  foreach (array(
    $tool_root.'/nc-webdav-source',
    $tool_root.'/nc-webdav-preview',
    $tool_root.'/nc-webdav-gallery',
  ) as $generated_root)
  {
    $removed_files += bratonien_tools_nc_legacy_remove_tree($generated_root, $data_root);
  }

  if (defined('PWG_DERIVATIVE_DIR'))
  {
    $derivative_root = rtrim(PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR, '/');
    foreach (array(
      $derivative_root.'/_data/bratonien-tools/nc-webdav-source',
      $derivative_root.'/_data/bratonien-tools/nc-webdav-gallery',
    ) as $generated_derivative_root)
    {
      $removed_files += bratonien_tools_nc_legacy_remove_tree($generated_derivative_root, $derivative_root);
    }
  }

  $removed_state_files = bratonien_tools_nc_legacy_remove_state_files($tool_root.'/nc-connector-state');

  if ($ids)
  {
    update_category('all');
    invalidate_user_cache(true);
  }

  $result = array(
    'timestamp'=>time(),
    'removed_image_records'=>count($ids),
    'removed_generated_entries'=>$removed_files,
    'removed_state_files'=>$removed_state_files,
  );

  if (function_exists('conf_update_param'))
  {
    conf_update_param('bratonien_nc_legacy_reset_0977', json_encode($result));
  }

  return $result;
}
