#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$pluginRoot = dirname(__DIR__);
$piwigoRoot = dirname($pluginRoot, 2);
define('PHPWG_ROOT_PATH', rtrim($piwigoRoot, '/').'/');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/cleanup-webdav-piwigo.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

function bratonien_cleanup_tree($path, $allowed_root)
{
  $path = rtrim((string)$path, '/');
  $allowed_root = rtrim((string)$allowed_root, '/');
  if ($path === '' || $allowed_root === '' || strpos($path, $allowed_root.'/') !== 0 || !file_exists($path)) return;

  if (is_link($path) || is_file($path))
  {
    @unlink($path);
    return;
  }

  $items = @scandir($path);
  if (!is_array($items)) return;
  foreach ($items as $item)
  {
    if ($item === '.' || $item === '..') continue;
    bratonien_cleanup_tree($path.'/'.$item, $allowed_root);
  }
  @rmdir($path);
}

function bratonien_webdav_site_images($site_id)
{
  $rows = array();
  $query = '
SELECT DISTINCT i.id, i.path, i.representative_ext
  FROM '.IMAGES_TABLE.' AS i
  LEFT JOIN '.CATEGORIES_TABLE.' AS sc ON sc.id = i.storage_category_id
  LEFT JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  LEFT JOIN '.CATEGORIES_TABLE.' AS vc ON vc.id = ic.category_id
  WHERE sc.site_id = '.(int)$site_id.' OR vc.site_id = '.(int)$site_id.'
;';
  $result = pwg_query($query);
  while ($row = pwg_db_fetch_assoc($result))
  {
    $row['id'] = (int)$row['id'];
    $rows[$row['id']] = $row;
  }
  return $rows;
}

try
{
  $connection_table = function_exists('bratonien_tools_nc_connector_table')
    ? bratonien_tools_nc_connector_table()
    : $GLOBALS['prefixeTable'].'bratonien_tools_nc_connections';

  $active = array();
  $exists = pwg_query("SHOW TABLES LIKE '".pwg_db_real_escape_string($connection_table)."'");
  if (pwg_db_num_rows($exists))
  {
    $result = pwg_query("SELECT id FROM `".$connection_table."`");
    while ($row = pwg_db_fetch_assoc($result))
    {
      $active[(int)$row['id']] = true;
    }
  }

  $site_prefix = './_data/bratonien-tools/nc-webdav-gallery/connection-';
  $result = pwg_query(
    "SELECT id, galleries_url FROM ".SITES_TABLE.
    " WHERE galleries_url LIKE '".pwg_db_real_escape_string($site_prefix)."%'"
  );

  $removed_sites = 0;
  $removed_images = 0;
  while ($site = pwg_db_fetch_assoc($result))
  {
    $site_id = (int)$site['id'];
    $site_url = (string)$site['galleries_url'];
    if (!preg_match('#^\./_data/bratonien-tools/nc-webdav-gallery/connection-([0-9]+)/$#', $site_url, $match)) continue;

    $connection_id = (int)$match[1];
    if ($connection_id < 1 || isset($active[$connection_id])) continue;

    $images = bratonien_webdav_site_images($site_id);
    foreach ($images as $row)
    {
      try
      {
        delete_element_derivatives($row);
      }
      catch (Throwable $e)
      {
        fwrite(STDERR, 'WebDAV-Cleanup: Derivate von Bild #'.(int)$row['id'].' konnten nicht vollständig entfernt werden: '.$e->getMessage()."\n");
      }
    }

    delete_site($site_id);

    if ($images)
    {
      $ids = array_keys($images);
      $remaining = query2array(
        'SELECT id FROM '.IMAGES_TABLE.' WHERE id IN ('.implode(',', array_map('intval', $ids)).')',
        null,
        'id'
      );
      if ($remaining)
      {
        delete_elements(array_map('intval', $remaining), false);
      }
    }

    $removed_sites++;
    $removed_images += count($images);
    echo 'NC WebDAV: entferne verwaiste Piwigo-Site '.$site_id.' für gelöschte Verbindung '.$connection_id.".\n";

    $gallery_root = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-webdav-gallery';
    $source_root = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-webdav-source';
    $preview_root = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-webdav-preview';
    bratonien_cleanup_tree($gallery_root.'/connection-'.$connection_id, $gallery_root);
    bratonien_cleanup_tree($source_root.'/connection-'.$connection_id, $source_root);
    bratonien_cleanup_tree($preview_root.'/connection-'.$connection_id, $preview_root);

    $state_root = '/var/lib/bratonien-tools/nc-connector';
    bratonien_cleanup_tree($state_root.'/connection-'.$connection_id, $state_root);

    foreach (glob('/etc/bratonien-tools/nc-connector/webdav-connection-'.$connection_id.'.*') ?: array() as $file)
    {
      @unlink($file);
    }

    $status_root = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-connector-status';
    @unlink($status_root.'/connection-'.$connection_id.'.json');
    @unlink($status_root.'/deleted-'.$connection_id);
  }

  if ($removed_sites > 0)
  {
    invalidate_user_cache(true);
  }

  echo 'NC WebDAV Piwigo-Cleanup: sites='.$removed_sites.' bilder='.$removed_images."\n";
  exit(0);
}
catch (Throwable $e)
{
  fwrite(STDERR, 'NC WebDAV Piwigo-Cleanup: '.$e->getMessage()."\n");
  exit(1);
}
