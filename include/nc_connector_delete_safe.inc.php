<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_db_prefix_from_absolute($path)
{
  $path = rtrim(str_replace('\\', '/', (string)$path), '/');
  $piwigo_root = rtrim(str_replace('\\', '/', PHPWG_ROOT_PATH), '/');
  if ($path === '' || strpos($path, $piwigo_root.'/') !== 0) return '';

  $relative = ltrim(substr($path, strlen($piwigo_root)), '/');
  return $relative === '' ? '' : './'.rtrim($relative, '/').'/';
}

function bratonien_tools_nc_connector_owned_db_prefixes(array $connection)
{
  $id = (int)($connection['id'] ?? 0);
  if ($id < 1) return array();

  $prefixes = array(
    './_data/bratonien-tools/nc-webdav-gallery/connection-'.$id.'/',
    './_data/bratonien-tools/nc-webdav-source/connection-'.$id.'/',
    './galleries/bratonien-webdav-'.$id.'/',
  );

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $configured = bratonien_tools_nc_connector_db_prefix_from_absolute($config['parallel_gallery_root'] ?? '');
  if ($configured !== '') $prefixes[] = $configured;

  return array_values(array_unique($prefixes));
}

function bratonien_tools_nc_connector_owned_site_urls(array $connection)
{
  $id = (int)($connection['id'] ?? 0);
  if ($id < 1) return array();

  $urls = array(
    './_data/bratonien-tools/nc-webdav-gallery/connection-'.$id.'/',
    './galleries/bratonien-webdav-'.$id.'/',
  );

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $configured = bratonien_tools_nc_connector_db_prefix_from_absolute($config['parallel_gallery_root'] ?? '');
  if ($configured !== '') $urls[] = $configured;

  return array_values(array_unique($urls));
}

function bratonien_tools_nc_connector_remove_webdav_piwigo_content(array $connection)
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  $prefixes = bratonien_tools_nc_connector_owned_db_prefixes($connection);
  if (!$prefixes) return array('sites'=>0, 'images'=>0);

  $where = array();
  foreach ($prefixes as $prefix)
  {
    $escaped = pwg_db_real_escape_string(addcslashes($prefix, '_%\\'));
    $where[] = "path LIKE '".$escaped."%' ESCAPE '\\\\'";
  }

  $image_rows = array();
  $result = pwg_query(
    'SELECT id, path, representative_ext FROM '.IMAGES_TABLE.
    ' WHERE '.implode(' OR ', $where)
  );
  while ($row = pwg_db_fetch_assoc($result))
  {
    $path = (string)$row['path'];
    $owned = false;
    foreach ($prefixes as $prefix)
    {
      if (strpos($path, $prefix) === 0)
      {
        $owned = true;
        break;
      }
    }
    if (!$owned) continue;

    $row['id'] = (int)$row['id'];
    $image_rows[$row['id']] = $row;
  }

  foreach ($image_rows as $row)
  {
    try
    {
      delete_element_derivatives($row);
    }
    catch (Throwable $e)
    {
      error_log('Bratonien WebDAV delete derivative cleanup #'.(int)$row['id'].': '.$e->getMessage());
    }
  }

  if ($image_rows)
  {
    delete_elements(array_map('intval', array_keys($image_rows)), false);
  }

  $site_count = 0;
  foreach (bratonien_tools_nc_connector_owned_site_urls($connection) as $site_url)
  {
    $result = pwg_query(
      "SELECT id FROM ".SITES_TABLE.
      " WHERE galleries_url='".pwg_db_real_escape_string($site_url)."'"
    );
    while ($row = pwg_db_fetch_assoc($result))
    {
      delete_site((int)$row['id']);
      $site_count++;
    }
  }

  update_category('all');
  invalidate_user_cache(true);
  return array('sites'=>$site_count, 'images'=>count($image_rows));
}

/**
 * Delete exactly the connection selected by the user.
 * No other connector record is implicitly removed.
 */
function bratonien_tools_nc_connector_delete_safe()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, false);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }

  $cleanup = array('sites'=>0, 'images'=>0);
  if (
    (string)($connection['adapter'] ?? '') === 'remote'
    && (string)($connection['config']['source_mode'] ?? '') === 'webdav-placeholder'
  )
  {
    $cleanup = bratonien_tools_nc_connector_remove_webdav_piwigo_content($connection);
  }

  $table = bratonien_tools_nc_connector_table();
  pwg_query("DELETE FROM `$table` WHERE id=".$id." LIMIT 1");

  $status_dir = rtrim(PHPWG_ROOT_PATH, '/').'/_data/bratonien-tools/nc-connector-status';
  $public_status = $status_dir.'/connection-'.$id.'.json';
  if (is_file($public_status)) @unlink($public_status);

  if (is_dir($status_dir) || @mkdir($status_dir, 0755, true))
  {
    @file_put_contents($status_dir.'/deleted-'.$id, date('c')."\n", LOCK_EX);
  }

  return array(
    'message'=>'Verbindung wurde gelöscht. '.(int)$cleanup['images'].' eindeutig zu dieser Verbindung gehörende Bilder wurden aus Piwigo entfernt. Nextcloud-Dateien blieben unverändert.',
  );
}
