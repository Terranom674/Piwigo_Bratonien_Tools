<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Registers the connector-owned filesystem synchronization endpoint.
 *
 * Authentication and authorization are delegated to Piwigo's Web API.
 * The method is administrator-only and POST-only so an API key inherits the
 * permissions of its Piwigo user without storing that user's password.
 */
function bratonien_tools_register_ws_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'bratonien.nc.sync',
    'bratonien_tools_ws_nc_sync',
    array(
      'site_id' => array(
        'default' => 1,
        'type' => WS_TYPE_ID,
        'info' => 'Piwigo storage site to synchronize. Default: 1.',
      ),
      'simulate' => array(
        'default' => true,
        'type' => WS_TYPE_BOOL,
        'info' => 'Simulation only. Productive API synchronization is disabled until parity has been verified.',
      ),
    ),
    'Simulates the local Piwigo filesystem synchronization used by the Bratonien NC Connector.',
    null,
    array(
      'admin_only' => true,
      'post_only' => true,
    )
  );
}

function bratonien_tools_ws_nc_sync_error(&$errors, $path, $type)
{
  $errors[] = array(
    'path' => (string)$path,
    'type' => (string)$type,
  );
}

/**
 * Read-only parity test for Piwigo 16.4.0 admin/site_update.php with the
 * connector's fixed settings:
 *   sync=files, privacy_level=0, sync_meta=1, subcats-included=1,
 *   no cat, no caddie, no meta_all, no meta_empty_overrides.
 *
 * The simulation deliberately performs no INSERT/UPDATE/DELETE and triggers
 * no Piwigo activity/events. It only calculates what the matching core admin
 * synchronization would attempt to change.
 */
function bratonien_tools_ws_nc_sync($params, &$service)
{
  global $conf, $user, $logger;

  if (empty($conf['enable_synchronization']))
  {
    return new PwgError(403, 'Piwigo filesystem synchronization is disabled.');
  }

  $piwigo_version = defined('PHPWG_VERSION') ? (string)PHPWG_VERSION : '';
  if ($piwigo_version !== '16.4.0')
  {
    return new PwgError(
      409,
      'Bratonien API synchronization is not approved for Piwigo '.$piwigo_version.'. Use the administrator fallback until this Piwigo version has been verified.'
    );
  }

  $simulate = !isset($params['simulate']) || (bool)$params['simulate'];
  if (!$simulate)
  {
    return new PwgError(
      409,
      'Productive Bratonien API synchronization is not enabled yet. Run the simulation and verify parity with Piwigo admin synchronization first.'
    );
  }

  $site_id = isset($params['site_id']) ? (int)$params['site_id'] : 1;
  if ($site_id < 1)
  {
    return new PwgError(400, 'Invalid site_id.');
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
  include_once(PHPWG_ROOT_PATH.'admin/site_reader_local.php');

  $query = '\nSELECT galleries_url\n  FROM '.SITES_TABLE.'\n  WHERE id = '.$site_id.'\n  LIMIT 1';
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

  $errors = array();
  $site_reader = new LocalSiteReader($site_url);
  if (!$site_reader->open())
  {
    return new PwgError(500, 'Piwigo could not open the configured local site.');
  }

  $counts = array(
    'new_categories' => 0,
    'del_categories' => 0,
    'new_elements' => 0,
    'del_elements' => 0,
    'upd_elements' => 0,
    'new_formats' => 0,
    'del_formats' => 0,
    'metadata_candidates' => 0,
  );

  // -------------------------------------------------------------------
  // Directories / physical categories
  // -------------------------------------------------------------------
  $query = '\nSELECT id, uppercats, global_rank, status, visible\n  FROM '.CATEGORIES_TABLE.'\n  WHERE dir IS NOT NULL\n    AND site_id = '.$site_id;
  $db_categories = hash_from_query($query, 'id');
  $db_fulldirs = get_fulldirs(array_keys($db_categories));
  $basedir = preg_replace('#/*$#', '', $site_url);
  $db_fulldirs = array_flip($db_fulldirs);

  $fs_fulldirs = $site_reader->get_full_directories($basedir);
  $next_id = pwg_db_nextval('id', CATEGORIES_TABLE);

  foreach (array_diff($fs_fulldirs, array_keys($db_fulldirs)) as $fulldir)
  {
    $dir = basename($fulldir);
    if (!preg_match($conf['sync_chars_regex'], $dir))
    {
      bratonien_tools_ws_nc_sync_error($errors, $fulldir, 'PWG-UPDATE-1');
      continue;
    }

    // site_update.php also adds newly discovered categories to its in-memory
    // maps during simulation so files below them can be detected in the same
    // run. Synthetic IDs reproduce that behavior without database writes.
    $virtual_id = $next_id++;
    $db_fulldirs[$fulldir] = $virtual_id;
    $db_categories[$virtual_id] = array('id'=>$virtual_id);
    $counts['new_categories']++;
  }

  $to_delete = array();
  foreach (array_diff(array_keys($db_fulldirs), $fs_fulldirs) as $fulldir)
  {
    // A just-added virtual directory is always in $fs_fulldirs and therefore
    // cannot land here.
    $to_delete[] = $db_fulldirs[$fulldir];
  }
  $counts['del_categories'] = count($to_delete);

  // -------------------------------------------------------------------
  // Files / images / formats
  // -------------------------------------------------------------------
  $fs = $site_reader->get_elements($basedir);
  $cat_ids = array_diff(array_keys($db_categories), $to_delete);
  $db_elements = array();

  if (count($cat_ids) > 0)
  {
    $query = '\nSELECT id, path\n  FROM '.IMAGES_TABLE.'\n  WHERE storage_category_id IN ('.wordwrap(implode(', ', $cat_ids), 160, "\n").')';
    $db_elements = simple_hash_from_query($query, 'id', 'path');
  }

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
      bratonien_tools_ws_nc_sync_error($errors, $path, 'PWG-UPDATE-1');
      continue;
    }

    $counts['new_elements']++;
    if (!empty($conf['enable_formats']) && isset($fs[$path]['formats']) && is_array($fs[$path]['formats']))
    {
      $counts['new_formats'] += count($fs[$path]['formats']);
    }
  }

  if (!empty($conf['enable_formats']) && count($db_elements) > 0)
  {
    $db_elements_flip = array_flip($db_elements);
    $existing_ids = array();
    foreach (array_intersect_key($fs, $db_elements_flip) as $path => $unused)
    {
      $existing_ids[] = $db_elements_flip[$path];
    }

    if (count($existing_ids) > 0)
    {
      $db_formats = array();
      $query = '\nSELECT *\n  FROM '.IMAGE_FORMAT_TABLE.'\n  WHERE image_id IN ('.implode(',', $existing_ids).')';
      $result = pwg_query($query);
      while ($row = pwg_db_fetch_assoc($result))
      {
        if (!isset($db_formats[$row['image_id']]))
        {
          $db_formats[$row['image_id']] = array();
        }
        $db_formats[$row['image_id']][$row['ext']] = $row['format_id'];
      }

      foreach ($db_formats as $image_id => $formats)
      {
        $path = $db_elements[$image_id];
        $filesystem_formats = isset($fs[$path]['formats']) && is_array($fs[$path]['formats']) ? $fs[$path]['formats'] : array();
        $counts['del_formats'] += count(array_diff_key($formats, $filesystem_formats));
      }

      foreach ($existing_ids as $image_id)
      {
        $path = $db_elements[$image_id];
        $known_formats = isset($db_formats[$image_id]) ? $db_formats[$image_id] : array();
        $filesystem_formats = isset($fs[$path]['formats']) && is_array($fs[$path]['formats']) ? $fs[$path]['formats'] : array();
        $counts['new_formats'] += count(array_diff_key($filesystem_formats, $known_formats));
      }
    }
  }

  $counts['del_elements'] = count(array_diff($db_elements, array_keys($fs)));

  // site_update.php updates representative_ext for all currently registered
  // files. In simulation newly found files/categories are not yet in the DB,
  // so get_filelist() intentionally sees only the current database state.
  $files = get_filelist('', $site_id, true, false);
  $update_count = 0;
  foreach ($files as $id => $file)
  {
    $data = $site_reader->get_element_update_attributes($file['path']);
    if (is_array($data))
    {
      $update_count++;
    }
  }
  $counts['upd_elements'] = $update_count;

  // The connector does not set meta_all. Piwigo therefore processes only
  // files whose date_metadata_update is still NULL.
  $metadata_files = get_filelist('', $site_id, true, true);
  $counts['metadata_candidates'] = count($metadata_files);

  return array(
    'mode' => 'simulation',
    'approved_piwigo_version' => '16.4.0',
    'piwigo_version' => $piwigo_version,
    'site_id' => $site_id,
    'site_url' => $site_url,
    'counts' => $counts,
    'errors' => $errors,
    'error_count' => count($errors),
    'database_writes' => false,
    'fallback' => 'administrator',
    'connected_with' => isset($_SESSION['connected_with']) ? (string)$_SESSION['connected_with'] : '',
    'username' => isset($user['username']) ? (string)$user['username'] : '',
    'status' => isset($user['status']) ? (string)$user['status'] : '',
  );
}
