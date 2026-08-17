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
    'Runs the approved direct Bratonien filesystem synchronization for the NC Connector.',
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

function bratonien_tools_ws_nc_sync_productive($params, &$service)
{
  global $conf, $user;

  $piwigo_version = defined('PHPWG_VERSION') ? (string)PHPWG_VERSION : '';
  if ($piwigo_version !== '16.4.0')
  {
    return new PwgError(
      409,
      'Bratonien API synchronization is not approved for Piwigo '.$piwigo_version.'. Use the administrator fallback until this Piwigo version has been verified.'
    );
  }

  if (empty($conf['enable_synchronization']))
  {
    return new PwgError(403, 'Piwigo filesystem synchronization is disabled.');
  }

  $site_id = isset($params['site_id']) ? (int)$params['site_id'] : 1;
  if ($site_id < 1)
  {
    return new PwgError(400, 'Invalid site_id.');
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
  include_once(PHPWG_ROOT_PATH.'admin/site_reader_local.php');

  $query = 'SELECT galleries_url FROM '.SITES_TABLE.' WHERE id = '.$site_id.' LIMIT 1';
  $result = pwg_query($query);
  if (!pwg_db_num_rows($result))
  {
    return new PwgError(404, 'Piwigo site does not exist.');
  }

  list($site_url) = pwg_db_fetch_row($result);
  if (url_is_remote($site_url))
  {
    return new PwgError(400, 'Remote Piwigo sites are not supported by this synchronization method.');
  }

  $site_reader = new LocalSiteReader($site_url);
  if (!$site_reader->open())
  {
    return new PwgError(500, 'Piwigo could not open the configured local site.');
  }

  list($dbnow) = pwg_db_fetch_row(pwg_query('SELECT NOW()'));
  $errors = array();
  $counts = array(
    'new_categories' => 0,
    'del_categories' => 0,
    'new_elements' => 0,
    'del_elements' => 0,
    'upd_elements' => 0,
    'new_formats' => 0,
    'del_formats' => 0,
    'metadata_candidates' => 0,
    'metadata_updated' => 0,
  );

  try
  {
    $query = 'SELECT id, id_uppercat, uppercats, global_rank, status, visible FROM '.CATEGORIES_TABLE.' WHERE dir IS NOT NULL AND site_id = '.$site_id;
    $db_categories = hash_from_query($query, 'id');
    $db_fulldirs = get_fulldirs(array_keys($db_categories));
    $basedir = preg_replace('#/*$#', '', $site_url);
    $db_fulldirs = array_flip($db_fulldirs);
    $fs_fulldirs = $site_reader->get_full_directories($basedir);

    $next_rank = array('NULL'=>1);
    $result = pwg_query('SELECT id FROM '.CATEGORIES_TABLE);
    while ($row = pwg_db_fetch_assoc($result))
    {
      $next_rank[$row['id']] = 1;
    }
    $result = pwg_query('SELECT id_uppercat, MAX(`rank`)+1 AS next_rank FROM '.CATEGORIES_TABLE.' GROUP BY id_uppercat');
    while ($row = pwg_db_fetch_assoc($result))
    {
      $key = empty($row['id_uppercat']) ? 'NULL' : $row['id_uppercat'];
      $next_rank[$key] = (int)$row['next_rank'];
    }

    $next_id = pwg_db_nextval('id', CATEGORIES_TABLE);
    $category_inserts = array();

    foreach (array_diff($fs_fulldirs, array_keys($db_fulldirs)) as $fulldir)
    {
      $dir = basename($fulldir);
      if (!preg_match($conf['sync_chars_regex'], $dir))
      {
        bratonien_tools_nc_productive_error($errors, $fulldir, 'PWG-UPDATE-1');
        continue;
      }

      $insert = array(
        'id' => $next_id++,
        'dir' => $dir,
        'name' => str_replace('_', ' ', $dir),
        'site_id' => $site_id,
        'commentable' => boolean_to_string($conf['newcat_default_commentable']),
        'status' => 'private',
        'visible' => boolean_to_string($conf['newcat_default_visible']),
      );

      $parent_path = dirname($fulldir);
      if (isset($db_fulldirs[$parent_path]))
      {
        $parent = $db_fulldirs[$parent_path];
        $insert['id_uppercat'] = $parent;
        $insert['uppercats'] = $db_categories[$parent]['uppercats'].','.$insert['id'];
        $insert['rank'] = $next_rank[$parent]++;
        $insert['global_rank'] = $db_categories[$parent]['global_rank'].'.'.$insert['rank'];
        if ((string)$db_categories[$parent]['visible'] === 'false')
        {
          $insert['visible'] = 'false';
        }
      }
      else
      {
        $insert['uppercats'] = (string)$insert['id'];
        $insert['rank'] = $next_rank['NULL']++;
        $insert['global_rank'] = (string)$insert['rank'];
      }

      $category_inserts[] = $insert;
      $db_categories[$insert['id']] = array(
        'id' => $insert['id'],
        'id_uppercat' => $insert['id_uppercat'] ?? null,
        'uppercats' => $insert['uppercats'],
        'global_rank' => $insert['global_rank'],
        'status' => 'private',
        'visible' => $insert['visible'],
      );
      $db_fulldirs[$fulldir] = $insert['id'];
      $next_rank[$insert['id']] = 1;
    }

    if ($category_inserts)
    {
      mass_inserts(
        CATEGORIES_TABLE,
        array('id','dir','name','site_id','id_uppercat','uppercats','commentable','visible','status','rank','global_rank'),
        $category_inserts
      );
      $category_ids = array_map(function ($row) { return (int)$row['id']; }, $category_inserts);
      pwg_activity('album', $category_ids, 'add', array('sync'=>true));
      add_permission_on_category($category_ids, get_admins());
      $counts['new_categories'] = count($category_ids);
    }

    $to_delete_categories = array();
    foreach (array_diff(array_keys($db_fulldirs), $fs_fulldirs) as $fulldir)
    {
      $to_delete_categories[] = (int)$db_fulldirs[$fulldir];
      unset($db_fulldirs[$fulldir]);
    }
    if ($to_delete_categories)
    {
      delete_categories($to_delete_categories);
      $counts['del_categories'] = count($to_delete_categories);
    }

    $fs = $site_reader->get_elements($basedir);
    $cat_ids = array_diff(array_keys($db_categories), $to_delete_categories);
    $db_elements = array();
    if ($cat_ids)
    {
      $query = 'SELECT id, path FROM '.IMAGES_TABLE.' WHERE storage_category_id IN ('.implode(',', array_map('intval', $cat_ids)).')';
      $db_elements = simple_hash_from_query($query, 'id', 'path');
    }

    $next_element_id = pwg_db_nextval('id', IMAGES_TABLE);
    $image_inserts = array();
    $image_links = array();
    $format_inserts = array();
    $new_image_ids = array();

    foreach (array_diff(array_keys($fs), $db_elements) as $path)
    {
      $dirname = dirname($path);
      if (!isset($db_fulldirs[$dirname]))
      {
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
        'id' => $id,
        'file' => $filename,
        'name' => get_name_from_file($filename),
        'date_available' => $dbnow,
        'path' => $path,
        'representative_ext' => $fs[$path]['representative_ext'],
        'storage_category_id' => $db_fulldirs[$dirname],
        'added_by' => (int)$user['id'],
      );
      $image_links[] = array('image_id'=>$id, 'category_id'=>$db_fulldirs[$dirname]);
      $new_image_ids[] = $id;

      if (!empty($conf['enable_formats']) && !empty($fs[$path]['formats']))
      {
        foreach ($fs[$path]['formats'] as $ext => $filesize)
        {
          $format_inserts[] = array('image_id'=>$id, 'ext'=>$ext, 'filesize'=>$filesize);
        }
      }
    }

    if ($image_inserts)
    {
      mass_inserts(IMAGES_TABLE, array_keys($image_inserts[0]), $image_inserts);
      mass_inserts(IMAGE_CATEGORY_TABLE, array_keys($image_links[0]), $image_links);
      pwg_activity('photo', $new_image_ids, 'add', array('sync'=>true));
      $counts['new_elements'] = count($image_inserts);
    }
    if ($format_inserts)
    {
      mass_inserts(IMAGE_FORMAT_TABLE, array_keys($format_inserts[0]), $format_inserts);
      $counts['new_formats'] += count($format_inserts);
    }

    if (!empty($conf['enable_formats']) && $db_elements)
    {
      $db_elements_flip = array_flip($db_elements);
      $existing_ids = array();
      foreach (array_intersect_key($fs, $db_elements_flip) as $path => $unused)
      {
        $existing_ids[] = (int)$db_elements_flip[$path];
      }

      if ($existing_ids)
      {
        $db_formats = array();
        $result = pwg_query('SELECT * FROM '.IMAGE_FORMAT_TABLE.' WHERE image_id IN ('.implode(',', $existing_ids).')');
        while ($row = pwg_db_fetch_assoc($result))
        {
          $db_formats[$row['image_id']][$row['ext']] = $row['format_id'];
        }

        $formats_to_delete = array();
        $formats_to_insert = array();
        foreach ($existing_ids as $image_id)
        {
          $path = $db_elements[$image_id];
          $known = $db_formats[$image_id] ?? array();
          $present = $fs[$path]['formats'] ?? array();
          foreach (array_diff_key($known, $present) as $format_id)
          {
            $formats_to_delete[] = (int)$format_id;
          }
          foreach (array_diff_key($present, $known) as $ext => $filesize)
          {
            $formats_to_insert[] = array('image_id'=>$image_id, 'ext'=>$ext, 'filesize'=>$filesize);
          }
        }

        if ($formats_to_delete)
        {
          pwg_query('DELETE FROM '.IMAGE_FORMAT_TABLE.' WHERE format_id IN ('.implode(',', $formats_to_delete).')');
          $counts['del_formats'] = count($formats_to_delete);
        }
        if ($formats_to_insert)
        {
          mass_inserts(IMAGE_FORMAT_TABLE, array_keys($formats_to_insert[0]), $formats_to_insert);
          $counts['new_formats'] += count($formats_to_insert);
        }
      }
    }

    $to_delete_elements = array();
    foreach (array_diff($db_elements, array_keys($fs)) as $path)
    {
      $id = array_search($path, $db_elements, true);
      if ($id !== false)
      {
        $to_delete_elements[] = (int)$id;
      }
    }
    if ($to_delete_elements)
    {
      delete_elements($to_delete_elements);
      $counts['del_elements'] = count($to_delete_elements);
    }

    update_category('all');
    update_global_rank();

    $files = get_filelist('', $site_id, true, false);
    $updates = array();
    foreach ($files as $id => $file)
    {
      $data = $site_reader->get_element_update_attributes($file['path']);
      if (!is_array($data))
      {
        continue;
      }
      $data['id'] = $id;
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

    $metadata_files = get_filelist('', $site_id, true, true);
    $counts['metadata_candidates'] = count($metadata_files);
    $metadata_updates = array();
    $tags_of = array();

    foreach ($metadata_files as $id => $element_infos)
    {
      $data = $site_reader->get_element_metadata($element_infos);
      if (!is_array($data))
      {
        bratonien_tools_nc_productive_error($errors, $element_infos['path'], 'PWG-ERROR-NO-FS');
        continue;
      }

      $data['date_metadata_update'] = $dbnow;
      $data['id'] = $id;
      $metadata_updates[] = $data;

      foreach (array('keywords','tags') as $key)
      {
        if (!isset($data[$key]))
        {
          continue;
        }
        $tags_of[$id] = $tags_of[$id] ?? array();
        foreach (explode(',', $data[$key]) as $tag_name)
        {
          $tags_of[$id][] = tag_id_from_tag_name($tag_name);
        }
      }
    }

    if ($metadata_updates)
    {
      mass_updates(
        IMAGES_TABLE,
        array(
          'primary'=>array('id'),
          'update'=>array_unique(array_merge(
            array_diff($site_reader->get_metadata_attributes(), array('keywords','tags')),
            array('date_metadata_update')
          )),
        ),
        $metadata_updates,
        MASS_UPDATES_SKIP_EMPTY
      );
    }
    if ($tags_of)
    {
      set_tags_of($tags_of);
    }
    $counts['metadata_updated'] = count($metadata_updates);

    // Mirror Piwigo 16.4.0 Maintenance -> "Update albums informations".
    // This repairs the derived album hierarchy and counters that the direct
    // API sync otherwise bypasses when no admin maintenance page is invoked.
    images_integrity();
    categories_integrity();
    update_uppercats();
    update_category('all');
    update_global_rank();
    invalidate_user_cache(true);

    // Mirror Piwigo 16.4.0 Maintenance -> "Update photos information".
    // This finalizes physical paths, ratings and derived photo information.
    images_integrity();
    update_path();
    include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
    update_rating_score();
    invalidate_user_cache();
  }
  catch (Throwable $e)
  {
    return new PwgError(500, 'Bratonien direct synchronization failed: '.$e->getMessage());
  }

  return array(
    'mode' => 'productive',
    'engine' => 'bratonien-direct',
    'approved_piwigo_version' => '16.4.0',
    'piwigo_version' => $piwigo_version,
    'site_id' => $site_id,
    'site_url' => $site_url,
    'counts' => $counts,
    'errors' => $errors,
    'error_count' => count($errors),
    'database_writes' => true,
    'username' => isset($user['username']) ? (string)$user['username'] : '',
    'status' => isset($user['status']) ? (string)$user['status'] : '',
  );
}
