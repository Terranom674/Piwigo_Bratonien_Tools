<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_webdav_site_id(array $connection)
{
  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  if ((string)($connection['adapter'] ?? '') !== 'remote') return 0;
  if ((string)($config['source_mode'] ?? '') !== 'webdav-placeholder') return 0;

  $gallery_root = rtrim((string)($config['parallel_gallery_root'] ?? ''), '/');
  $piwigo_root = rtrim(PHPWG_ROOT_PATH, '/');
  if ($gallery_root === '' || strpos($gallery_root, $piwigo_root.'/') !== 0) return 0;

  $relative = ltrim(substr($gallery_root, strlen($piwigo_root)), '/');
  if ($relative === '') return 0;
  $site_url = './'.rtrim($relative, '/').'/';

  $result = pwg_query(
    "SELECT id FROM ".SITES_TABLE.
    " WHERE galleries_url='".pwg_db_real_escape_string($site_url)."' LIMIT 1"
  );
  if (!pwg_db_num_rows($result)) return 0;

  $row = pwg_db_fetch_assoc($result);
  return (int)$row['id'];
}

function bratonien_tools_nc_connector_remove_webdav_piwigo_content(array $connection)
{
  $site_id = bratonien_tools_nc_connector_webdav_site_id($connection);
  if ($site_id < 1) return array('site_id'=>0, 'images'=>0);

  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  $image_rows = array();
  $query = '
SELECT DISTINCT i.id, i.path, i.representative_ext
  FROM '.IMAGES_TABLE.' AS i
  LEFT JOIN '.CATEGORIES_TABLE.' AS sc ON sc.id = i.storage_category_id
  LEFT JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  LEFT JOIN '.CATEGORIES_TABLE.' AS vc ON vc.id = ic.category_id
  WHERE sc.site_id = '.$site_id.' OR vc.site_id = '.$site_id.'
;';
  $result = pwg_query($query);
  while ($row = pwg_db_fetch_assoc($result))
  {
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

  delete_site($site_id);

  if ($image_rows)
  {
    $ids = array_keys($image_rows);
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

  invalidate_user_cache(true);
  return array('site_id'=>$site_id, 'images'=>count($image_rows));
}

/**
 * Delete a connector connection from Piwigo without depending on the web
 * server being allowed to write into root-owned runtime directories.
 * WebDAV-managed Piwigo records are removed before the connection itself so
 * deleted remote content cannot remain visible or addressable in Piwigo.
 */
function bratonien_tools_nc_connector_delete_safe()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, false);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }

  $cleanup = array('site_id'=>0, 'images'=>0);
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
  if (is_file($public_status))
  {
    @unlink($public_status);
  }

  if (is_dir($status_dir) || @mkdir($status_dir, 0755, true))
  {
    @file_put_contents($status_dir.'/deleted-'.$id, date('c')."\n", LOCK_EX);
  }

  if ((int)$cleanup['site_id'] > 0)
  {
    return array(
      'message'=>'Connector-Verbindung wurde gelöscht. Die zugehörigen Piwigo-Alben und '.(int)$cleanup['images'].' Bilder wurden aus Piwigo entfernt. Nextcloud-Dateien blieben unverändert. Laufzeit- und Vorschaudaten werden automatisch bereinigt.',
    );
  }

  return array(
    'message'=>'Connector-Verbindung wurde gelöscht. Verbliebene Laufzeitdateien werden vor dem nächsten Connector-Lauf automatisch entfernt. Quelldateien blieben unverändert.',
  );
}
