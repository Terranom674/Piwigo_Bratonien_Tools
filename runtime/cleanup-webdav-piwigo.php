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

function bratonien_connection_id_from_owned_path($path)
{
  $path = (string)$path;
  foreach (array(
    '#^\./_data/bratonien-tools/nc-webdav-gallery/connection-([0-9]+)/#',
    '#^\./_data/bratonien-tools/nc-webdav-source/connection-([0-9]+)/#',
    '#^\./galleries/bratonien-webdav-([0-9]+)/#',
  ) as $pattern)
  {
    if (preg_match($pattern, $path, $match)) return (int)$match[1];
  }
  return 0;
}

function bratonien_connection_id_from_site_url($url)
{
  $url = (string)$url;
  foreach (array(
    '#^\./_data/bratonien-tools/nc-webdav-gallery/connection-([0-9]+)/$#',
    '#^\./galleries/bratonien-webdav-([0-9]+)/$#',
  ) as $pattern)
  {
    if (preg_match($pattern, $url, $match)) return (int)$match[1];
  }
  return 0;
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

  $removed_images = 0;
  $stale_by_connection = array();
  $result = pwg_query(
    "SELECT id, path, representative_ext FROM ".IMAGES_TABLE.
    " WHERE path LIKE './_data/bratonien-tools/nc-webdav-gallery/connection-%'".
    " OR path LIKE './_data/bratonien-tools/nc-webdav-source/connection-%'".
    " OR path LIKE './galleries/bratonien-webdav-%'"
  );
  while ($row = pwg_db_fetch_assoc($result))
  {
    $connection_id = bratonien_connection_id_from_owned_path($row['path'] ?? '');
    if ($connection_id < 1 || isset($active[$connection_id])) continue;
    $row['id'] = (int)$row['id'];
    $stale_by_connection[$connection_id][$row['id']] = $row;
  }

  foreach ($stale_by_connection as $connection_id=>$images)
  {
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

    delete_elements(array_map('intval', array_keys($images)), false);
    $removed_images += count($images);
    echo 'NC WebDAV: entferne '.count($images).' verwaiste Bilder der gelöschten Verbindung '.$connection_id.".\n";
  }

  $removed_sites = 0;
  $result = pwg_query('SELECT id, galleries_url FROM '.SITES_TABLE);
  $stale_sites = array();
  while ($site = pwg_db_fetch_assoc($result))
  {
    $connection_id = bratonien_connection_id_from_site_url($site['galleries_url'] ?? '');
    if ($connection_id < 1 || isset($active[$connection_id])) continue;
    $stale_sites[] = array('id'=>(int)$site['id'], 'connection_id'=>$connection_id);
  }

  foreach ($stale_sites as $site)
  {
    delete_site($site['id']);
    $removed_sites++;
    echo 'NC WebDAV: entferne verwaiste Piwigo-Site '.$site['id'].' der gelöschten Verbindung '.$site['connection_id'].".\n";
  }

  $stale_connection_ids = array_unique(array_merge(
    array_map('intval', array_keys($stale_by_connection)),
    array_map(function($site) { return (int)$site['connection_id']; }, $stale_sites)
  ));

  $gallery_root = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-webdav-gallery';
  $source_root = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-webdav-source';
  $preview_root = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-webdav-preview';
  $legacy_root = PHPWG_ROOT_PATH.'galleries';
  $state_root = '/var/lib/bratonien-tools/nc-connector';
  $status_root = PHPWG_ROOT_PATH.'_data/bratonien-tools/nc-connector-status';

  foreach ($stale_connection_ids as $connection_id)
  {
    if ($connection_id < 1) continue;
    bratonien_cleanup_tree($gallery_root.'/connection-'.$connection_id, $gallery_root);
    bratonien_cleanup_tree($source_root.'/connection-'.$connection_id, $source_root);
    bratonien_cleanup_tree($preview_root.'/connection-'.$connection_id, $preview_root);
    bratonien_cleanup_tree($legacy_root.'/bratonien-webdav-'.$connection_id, $legacy_root);
    bratonien_cleanup_tree($state_root.'/connection-'.$connection_id, $state_root);

    foreach (glob('/etc/bratonien-tools/nc-connector/webdav-connection-'.$connection_id.'.*') ?: array() as $file)
    {
      @unlink($file);
    }
    @unlink($status_root.'/connection-'.$connection_id.'.json');
    @unlink($status_root.'/deleted-'.$connection_id);
  }

  if ($removed_sites > 0 || $removed_images > 0)
  {
    update_category('all');
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
