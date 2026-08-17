<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_register_nc_orphan_ws_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'bratonien.nc.syncOrphans',
    'bratonien_tools_ws_nc_sync_orphans',
    array(
      'site_id' => array(
        'default' => 1,
        'type' => WS_TYPE_ID,
      ),
      'simulate' => array(
        'default' => true,
        'type' => WS_TYPE_BOOL,
      ),
    ),
    'Synchronizes connector-managed files directly in a local Piwigo gallery root as orphan photos.',
    null,
    array(
      'admin_only' => true,
      'post_only' => true,
    )
  );
}

function bratonien_tools_nc_root_files($basedir)
{
  global $conf;

  $files = array();
  if (!is_dir($basedir) || !($handle = opendir($basedir)))
  {
    return $files;
  }

  $allowed = array_flip($conf['file_ext']);
  while (($node = readdir($handle)) !== false)
  {
    if ($node === '.' || $node === '..')
    {
      continue;
    }

    $path = $basedir.'/'.$node;
    if (!is_file($path))
    {
      continue;
    }

    $extension = strtolower(get_extension($node));
    if (!isset($allowed[$extension]))
    {
      continue;
    }

    $files[$path] = $node;
  }
  closedir($handle);
  ksort($files);

  return $files;
}

function bratonien_tools_nc_existing_root_orphans($basedir)
{
  $rows = array();
  $query = '\nSELECT id, path\n  FROM '.IMAGES_TABLE.'\n  WHERE storage_category_id IS NULL';
  $result = pwg_query($query);
  while ($row = pwg_db_fetch_assoc($result))
  {
    $path = (string)$row['path'];
    if (dirname($path) === $basedir)
    {
      $rows[$path] = (int)$row['id'];
    }
  }
  ksort($rows);

  return $rows;
}

function bratonien_tools_nc_register_root_orphan($path)
{
  global $user;

  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/image.class.php');

  if (!is_file($path))
  {
    throw new Exception('Root file is not readable: '.$path);
  }

  $md5sum = md5_file($path);
  if ($md5sum === false)
  {
    throw new Exception('Checksum could not be calculated: '.$path);
  }

  list($dbnow) = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
  $rotation_angle = pwg_image::get_rotation_angle($path);
  $rotation = pwg_image::get_rotation_code_from_angle($rotation_angle);
  $file_infos = pwg_image_infos($path);
  $file = pwg_db_real_escape_string(basename($path));

  $insert = array(
    'file' => $file,
    'name' => get_name_from_file($file),
    'date_available' => $dbnow,
    'path' => pwg_db_real_escape_string($path),
    'filesize' => $file_infos['filesize'],
    'width' => $file_infos['width'],
    'height' => $file_infos['height'],
    'md5sum' => $md5sum,
    'added_by' => (int)$user['id'],
    'rotation' => $rotation,
  );

  single_insert(IMAGES_TABLE, $insert);
  $image_id = (int)pwg_db_insert_id(IMAGES_TABLE);
  pwg_activity('photo', $image_id, 'add', array('sync'=>true, 'source'=>'bratonien_nc_root'));

  sync_metadata(array($image_id));

  $image_infos = array(
    'id' => $image_id,
    'path' => $path,
    'representative_ext' => null,
  );
  trigger_notify('loc_end_add_uploaded_file', $image_infos);

  return $image_id;
}

function bratonien_tools_ws_nc_sync_orphans($params, &$service)
{
  global $conf;

  if (empty($conf['enable_synchronization']))
  {
    return new PwgError(403, 'Piwigo filesystem synchronization is disabled.');
  }

  $piwigo_version = defined('PHPWG_VERSION') ? (string)PHPWG_VERSION : '';
  if ($piwigo_version !== '16.4.0')
  {
    return new PwgError(409, 'Bratonien orphan synchronization is approved only for Piwigo 16.4.0.');
  }

  $site_id = isset($params['site_id']) ? (int)$params['site_id'] : 1;
  if ($site_id < 1)
  {
    return new PwgError(400, 'Invalid site_id.');
  }

  $query = '\nSELECT galleries_url\n  FROM '.SITES_TABLE.'\n  WHERE id = '.$site_id.'\n  LIMIT 1';
  $result = pwg_query($query);
  if (!pwg_db_num_rows($result))
  {
    return new PwgError(404, 'Piwigo site does not exist.');
  }

  list($site_url) = pwg_db_fetch_row($result);
  if (url_is_remote($site_url))
  {
    return new PwgError(400, 'Remote Piwigo sites are not supported.');
  }

  $basedir = preg_replace('#/*$#', '', (string)$site_url);
  if (!is_dir($basedir))
  {
    return new PwgError(500, 'Piwigo gallery root is not accessible.');
  }

  $filesystem = bratonien_tools_nc_root_files($basedir);
  $database = bratonien_tools_nc_existing_root_orphans($basedir);
  $to_add = array_values(array_diff(array_keys($filesystem), array_keys($database)));
  $to_remove_paths = array_values(array_diff(array_keys($database), array_keys($filesystem)));
  $to_remove_ids = array();
  foreach ($to_remove_paths as $path)
  {
    $to_remove_ids[] = $database[$path];
  }

  $simulate = !isset($params['simulate']) || (bool)$params['simulate'];
  if ($simulate)
  {
    return array(
      'mode' => 'simulation',
      'piwigo_version' => $piwigo_version,
      'site_id' => $site_id,
      'site_url' => $site_url,
      'root_files' => count($filesystem),
      'registered_orphans' => count($database),
      'new_orphans' => count($to_add),
      'deleted_orphans' => count($to_remove_ids),
      'database_writes' => false,
    );
  }

  $added_ids = array();
  try
  {
    foreach ($to_add as $path)
    {
      $added_ids[] = bratonien_tools_nc_register_root_orphan($path);
    }

    if (count($to_remove_ids) > 0)
    {
      include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
      delete_elements($to_remove_ids, false);
    }

    if (count($added_ids) > 0 || count($to_remove_ids) > 0)
    {
      invalidate_user_cache();
    }
  }
  catch (Throwable $error)
  {
    return new PwgError(500, 'Bratonien orphan synchronization failed: '.$error->getMessage());
  }

  return array(
    'mode' => 'productive',
    'piwigo_version' => $piwigo_version,
    'site_id' => $site_id,
    'site_url' => $site_url,
    'root_files' => count($filesystem),
    'added_orphans' => count($added_ids),
    'deleted_orphans' => count($to_remove_ids),
    'added_ids' => $added_ids,
    'database_writes' => (count($added_ids) > 0 || count($to_remove_ids) > 0),
  );
}
