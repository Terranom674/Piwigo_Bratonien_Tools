<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_register_nc_productive_ws_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'bratonien.nc.syncProductive',
    'bratonien_tools_ws_nc_sync_productive',
    array(
      'site_id' => array(
        'default' => 1,
        'type' => WS_TYPE_ID,
        'info' => 'Piwigo storage site to synchronize. Default: 1.',
      ),
    ),
    'Synchronizes the NC Connector into the existing Piwigo album hierarchy.',
    null,
    array(
      'admin_only' => true,
      'post_only' => true,
    )
  );
}

function bratonien_tools_nc_productive_error(&$errors, $path, $type)
{
  $errors[] = array(
    'path' => (string)$path,
    'type' => (string)$type,
  );
}

function bratonien_tools_nc_relative_path($basedir, $path)
{
  $basedir = rtrim(str_replace('\\', '/', (string)$basedir), '/');
  $path = str_replace('\\', '/', (string)$path);
  if ($path === $basedir) return '';
  if (strpos($path, $basedir.'/') !== 0)
  {
    throw new RuntimeException('WebDAV-Pfad liegt ausserhalb der Connector-Wurzel: '.$path);
  }
  return trim(substr($path, strlen($basedir)), '/');
}

function bratonien_tools_nc_find_album($parent_id, $dir, $name, $excluded_site_id)
{
  $where_parent = $parent_id === null ? 'id_uppercat IS NULL' : 'id_uppercat='.(int)$parent_id;
  $dir_sql = pwg_db_real_escape_string((string)$dir);
  $name_sql = pwg_db_real_escape_string((string)$name);
  $query = '\nSELECT id, dir, name\n  FROM '.CATEGORIES_TABLE.'\n  WHERE '.$where_parent.'\n    AND (site_id IS NULL OR site_id <> '.(int)$excluded_site_id.')\n    AND (\n      dir = \\''.$dir_sql.'\\'\n      OR LOWER(name) = LOWER(\\''.$name_sql.'\\')\n    )\n  ORDER BY CASE WHEN dir = \\''.$dir_sql.'\\' THEN 0 ELSE 1 END, id\n  LIMIT 1\n;';
  $result = pwg_query($query);
  if (!pwg_db_num_rows($result)) return null;
  $row = pwg_db_fetch_assoc($result);
  return (int)$row['id'];
}

function bratonien_tools_nc_ensure_album_path($relative_dir, $excluded_site_id, array &$cache, array &$created_ids)
{
  $relative_dir = trim((string)$relative_dir, '/');
  if ($relative_dir === '') return null;
  if (isset($cache[$relative_dir])) return $cache[$relative_dir];

  $parts = explode('/', $relative_dir);
  $parent_id = null;
  $path = '';
  foreach ($parts as $part)
  {
    if ($part === '') continue;
    $path = $path === '' ? $part : $path.'/'.$part;
    if (isset($cache[$path]))
    {
      $parent_id = $cache[$path];
      continue;
    }

    $display_name = str_replace('_', ' ', $part);
    $album_id = bratonien_tools_nc_find_album($parent_id, $part, $display_name, $excluded_site_id);
    if ($album_id === null)
    {
      $created = create_virtual_category($display_name, $parent_id);
      if (!is_array($created) || empty($created['id']))
      {
        $detail = is_array($created) && !empty($created['error']) ? (string)$created['error'] : 'unbekannter Fehler';
        throw new RuntimeException('Album "'.$display_name.'" konnte nicht angelegt werden: '.$detail);
      }
      $album_id = (int)$created['id'];
      pwg_query('UPDATE '.CATEGORIES_TABLE." SET status='private' WHERE id=".$album_id.' LIMIT 1');
      add_permission_on_category(array($album_id), get_admins());
      $created_ids[] = $album_id;
    }

    $cache[$path] = $album_id;
    $parent_id = $album_id;
  }

  return $parent_id;
}

function bratonien_tools_nc_managed_images($basedir)
{
  $prefix = rtrim((string)$basedir, '/').'/';
  $escaped = pwg_db_real_escape_string(addcslashes($prefix, '_%\\'));
  $query = "SELECT id, path FROM ".IMAGES_TABLE." WHERE path LIKE '".$escaped."%' ESCAPE '\\\\'";
  return simple_hash_from_query($query, 'id', 'path');
}

function bratonien_tools_nc_remove_storage_categories($site_id)
{
  $ids = query2array('SELECT id FROM '.CATEGORIES_TABLE.' WHERE site_id='.(int)$site_id.' AND dir IS NOT NULL', null, 'id');
  if (!$ids) return 0;
  delete_categories(array_map('intval', $ids));
  return count($ids);
}

function bratonien_tools_ws_nc_sync_productive($params, &$service)
{
  global $conf, $user;

  $piwigo_version = defined('PHPWG_VERSION') ? (string)PHPWG_VERSION : '';
  if ($piwigo_version !== '16.4.0')
  {
    return new PwgError(409, 'Bratonien API synchronization is not approved for Piwigo '.$piwigo_version.'.');
  }
  if (empty($conf['enable_synchronization']))
  {
    return new PwgError(403, 'Piwigo filesystem synchronization is disabled.');
  }

  $site_id = isset($params['site_id']) ? (int)$params['site_id'] : 1;
  if ($site_id < 1) return new PwgError(400, 'Invalid site_id.');

  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
  include_once(PHPWG_ROOT_PATH.'admin/site_reader_local.php');

  $result = pwg_query('SELECT galleries_url FROM '.SITES_TABLE.' WHERE id='.$site_id.' LIMIT 1');
  if (!pwg_db_num_rows($result)) return new PwgError(404, 'Piwigo site does not exist.');
  list($site_url) = pwg_db_fetch_row($result);
  if (url_is_remote($site_url)) return new PwgError(400, 'Remote Piwigo sites are not supported.');

  $site_reader = new LocalSiteReader($site_url);
  if (!$site_reader->open()) return new PwgError(500, 'Piwigo could not open the configured local site.');

  $basedir = preg_replace('#/*$#', '', (string)$site_url);
  $errors = array();
  $counts = array(
    'reused_categories'=>0,
    'new_categories'=>0,
    'removed_duplicate_categories'=>0,
    'new_elements'=>0,
    'del_elements'=>0,
    'upd_elements'=>0,
  );

  try
  {
    $counts['removed_duplicate_categories'] = bratonien_tools_nc_remove_storage_categories($site_id);

    list($dbnow) = pwg_db_fetch_row(pwg_query('SELECT NOW()'));
    $fs_dirs = $site_reader->get_full_directories($basedir);
    usort($fs_dirs, function($a, $b)
    {
      return substr_count((string)$a, '/') <=> substr_count((string)$b, '/');
    });

    $album_cache = array();
    $created_ids = array();
    $dir_to_album = array();
    foreach ($fs_dirs as $full_dir)
    {
      $relative = bratonien_tools_nc_relative_path($basedir, $full_dir);
      if ($relative === '') continue;
      $before = count($created_ids);
      $album_id = bratonien_tools_nc_ensure_album_path($relative, $site_id, $album_cache, $created_ids);
      if ($album_id !== null)
      {
        $dir_to_album[$full_dir] = $album_id;
        if (count($created_ids) === $before) $counts['reused_categories']++;
      }
    }
    $counts['new_categories'] = count($created_ids);

    $fs = $site_reader->get_elements($basedir);
    $db_elements = bratonien_tools_nc_managed_images($basedir);
    $db_by_path = array_flip($db_elements);

    $to_delete = array();
    foreach ($db_elements as $id=>$path)
    {
      if (!array_key_exists($path, $fs)) $to_delete[] = (int)$id;
    }
    if ($to_delete)
    {
      delete_elements($to_delete, false);
      $counts['del_elements'] = count($to_delete);
      foreach ($to_delete as $id)
      {
        if (isset($db_elements[$id])) unset($db_by_path[$db_elements[$id]], $db_elements[$id]);
      }
    }

    $next_element_id = pwg_db_nextval('id', IMAGES_TABLE);
    $image_inserts = array();
    $image_links = array();
    $new_ids = array();
    $all_ids = array();

    foreach ($fs as $path=>$file_info)
    {
      $dirname = dirname($path);
      $relative_dir = bratonien_tools_nc_relative_path($basedir, $dirname);
      $category_id = null;
      if ($relative_dir !== '')
      {
        $category_id = $dir_to_album[$dirname] ?? bratonien_tools_nc_ensure_album_path($relative_dir, $site_id, $album_cache, $created_ids);
      }

      if (isset($db_by_path[$path]))
      {
        $id = (int)$db_by_path[$path];
        $all_ids[] = $id;
        pwg_query('DELETE FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id='.$id);
        if ($category_id !== null)
        {
          single_insert(IMAGE_CATEGORY_TABLE, array('image_id'=>$id, 'category_id'=>$category_id));
        }
        continue;
      }

      $filename = basename($path);
      if (!preg_match($conf['sync_chars_regex'], $filename))
      {
        bratonien_tools_nc_productive_error($errors, $path, 'PWG-UPDATE-1');
        continue;
      }

      $id = $next_element_id++;
      $image_inserts[] = array(
        'id'=>$id,
        'file'=>$filename,
        'name'=>get_name_from_file($filename),
        'date_available'=>$dbnow,
        'path'=>$path,
        'representative_ext'=>$file_info['representative_ext'],
        'storage_category_id'=>null,
        'added_by'=>(int)$user['id'],
      );
      if ($category_id !== null)
      {
        $image_links[] = array('image_id'=>$id, 'category_id'=>$category_id);
      }
      $new_ids[] = $id;
      $all_ids[] = $id;
    }

    if ($image_inserts)
    {
      mass_inserts(IMAGES_TABLE, array_keys($image_inserts[0]), $image_inserts);
      if ($image_links) mass_inserts(IMAGE_CATEGORY_TABLE, array_keys($image_links[0]), $image_links);
      pwg_activity('photo', $new_ids, 'add', array('sync'=>true));
      $counts['new_elements'] = count($new_ids);
    }

    $updates = array();
    foreach ($all_ids as $id)
    {
      $path_result = pwg_query('SELECT path FROM '.IMAGES_TABLE.' WHERE id='.(int)$id.' LIMIT 1');
      if (!pwg_db_num_rows($path_result)) continue;
      list($path) = pwg_db_fetch_row($path_result);
      $data = $site_reader->get_element_update_attributes($path);
      if (!is_array($data)) continue;
      $data['id'] = (int)$id;
      $updates[] = $data;
    }
    if ($updates)
    {
      mass_updates(
        IMAGES_TABLE,
        array('primary'=>array('id'), 'update'=>$site_reader->get_update_attributes()),
        $updates
      );
    }
    $counts['upd_elements'] = count($updates);

    images_integrity();
    categories_integrity();
    update_uppercats();
    update_category('all');
    update_global_rank();
    invalidate_user_cache();

    return array(
      'mode'=>'productive',
      'piwigo_version'=>$piwigo_version,
      'site_id'=>$site_id,
      'site_url'=>$site_url,
      'counts'=>$counts,
      'errors'=>$errors,
      'database_writes'=>array_sum($counts) > 0,
    );
  }
  catch (Throwable $error)
  {
    return new PwgError(500, 'Bratonien NC synchronization failed: '.$error->getMessage());
  }
}
