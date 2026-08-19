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

function bratonien_tools_nc_connector_gallery_db_prefix(array $connection)
{
  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $gallery_root = rtrim((string)($config['parallel_gallery_root'] ?? ''), '/');
  $piwigo_root = rtrim(PHPWG_ROOT_PATH, '/');
  if ($gallery_root === '' || strpos($gallery_root, $piwigo_root.'/') !== 0) return '';

  $relative = ltrim(substr($gallery_root, strlen($piwigo_root)), '/');
  return $relative === '' ? '' : './'.rtrim($relative, '/').'/';
}

function bratonien_tools_nc_connector_remove_webdav_piwigo_content(array $connection)
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  $site_id = bratonien_tools_nc_connector_webdav_site_id($connection);
  $db_prefix = bratonien_tools_nc_connector_gallery_db_prefix($connection);
  if ($db_prefix === '') return array('site_id'=>$site_id, 'images'=>0);

  $escaped = pwg_db_real_escape_string(addcslashes($db_prefix, '_%\\'));
  $image_rows = array();
  $result = pwg_query(
    "SELECT id, path, representative_ext FROM ".IMAGES_TABLE.
    " WHERE path LIKE '".$escaped."%' ESCAPE '\\\\'"
  );
  while ($row = pwg_db_fetch_assoc($result))
  {
    if (strpos((string)$row['path'], $db_prefix) !== 0) continue;
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

  if ($site_id > 0)
  {
    delete_site($site_id);
  }

  invalidate_user_cache(true);
  return array('site_id'=>$site_id, 'images'=>count($image_rows));
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
  if (is_file($public_status)) @unlink($public_status);

  if (is_dir($status_dir) || @mkdir($status_dir, 0755, true))
  {
    @file_put_contents($status_dir.'/deleted-'.$id, date('c')."\n", LOCK_EX);
  }

  return array(
    'message'=>'Verbindung wurde gelöscht. '.(int)$cleanup['images'].' eindeutig zu dieser Verbindung gehörende Bilder wurden aus Piwigo entfernt. Nextcloud-Dateien blieben unverändert.',
  );
}
