<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Adds the sequential-title action to Piwigo's global batch manager.
 *
 * The batch manager itself owns the selection. Bratonien Tools only receives
 * the validated collection through Piwigo's element_set_global_action event.
 */
function bratonien_tools_batch_titles_register_action()
{
  global $template;

  $defaults = array(
    'prefix' => isset($_POST['bratonien_title_prefix']) ? (string)$_POST['bratonien_title_prefix'] : '',
    'start' => isset($_POST['bratonien_title_start']) ? (int)$_POST['bratonien_title_start'] : 1,
    'padding' => isset($_POST['bratonien_title_padding']) ? (int)$_POST['bratonien_title_padding'] : 3,
    'replace_mode' => isset($_POST['bratonien_title_replace_mode']) ? (string)$_POST['bratonien_title_replace_mode'] : 'camera',
    'sort' => isset($_POST['bratonien_title_sort']) ? (string)$_POST['bratonien_title_sort'] : 'filename',
  );

  $template->assign('BRATONIEN_BATCH_TITLES', $defaults);
  $template->set_filename('bratonien_batch_titles_action', BRATONIEN_TOOLS_PATH.'template/batch_titles_action.tpl');

  $template->append('element_set_global_plugins_actions', array(
    'ID' => 'bratonien_sequential_titles',
    'NAME' => 'Fortlaufende Bildtitel',
    'CONTENT' => $template->parse('bratonien_batch_titles_action', true),
  ));
}

/**
 * Executes the action after Piwigo has built and validated the selected image
 * collection.
 */
function bratonien_tools_batch_titles_apply($action, $collection)
{
  global $page;

  if ($action !== 'bratonien_sequential_titles')
  {
    return;
  }

  $ids = array_values(array_unique(array_filter(array_map('intval', (array)$collection), function ($id) {
    return $id > 0;
  })));

  if (empty($ids))
  {
    $page['errors'][] = 'Keine Bilder für die Titelvergabe ausgewählt.';
    return;
  }

  $prefix = trim((string)($_POST['bratonien_title_prefix'] ?? ''));
  $start = isset($_POST['bratonien_title_start']) ? (int)$_POST['bratonien_title_start'] : 1;
  $padding = isset($_POST['bratonien_title_padding']) ? (int)$_POST['bratonien_title_padding'] : 3;
  $replace_mode = (string)($_POST['bratonien_title_replace_mode'] ?? 'camera');
  $sort = (string)($_POST['bratonien_title_sort'] ?? 'filename');

  if ($start < 0)
  {
    $page['errors'][] = 'Die Startnummer darf nicht negativ sein.';
    return;
  }

  if ($padding < 1 || $padding > 12)
  {
    $page['errors'][] = 'Die Stellenzahl muss zwischen 1 und 12 liegen.';
    return;
  }

  if (!in_array($replace_mode, array('camera', 'all'), true))
  {
    $page['errors'][] = 'Ungültiger Ersetzungsmodus.';
    return;
  }

  if (!in_array($sort, array('filename', 'date_creation', 'album_order'), true))
  {
    $page['errors'][] = 'Ungültige Sortierung.';
    return;
  }

  $images = bratonien_tools_batch_titles_get_images($ids, $sort);
  if ($images === false)
  {
    $page['errors'][] = 'Die aktuelle Albumreihenfolge kann nur verwendet werden, wenn die Stapelverarbeitung auf genau ein Album ohne Unteralben gefiltert ist.';
    return;
  }

  $number = $start;
  $updates = array();
  $changed_ids = array();

  foreach ($images as $image)
  {
    if ($replace_mode === 'camera' && !bratonien_tools_batch_titles_is_replaceable($image))
    {
      continue;
    }

    $number_text = str_pad((string)$number, $padding, '0', STR_PAD_LEFT);
    $title = $prefix === '' ? $number_text : $prefix.' - '.$number_text;

    $updates[] = array(
      'id' => (int)$image['id'],
      'name' => $title,
    );
    $changed_ids[] = (int)$image['id'];
    $number++;
  }

  if (empty($updates))
  {
    $page['infos'][] = 'Keine Bildtitel mussten geändert werden.';
    return;
  }

  mass_updates(
    IMAGES_TABLE,
    array('primary' => array('id'), 'update' => array('name')),
    $updates
  );

  if (function_exists('pwg_activity'))
  {
    pwg_activity('photo', $changed_ids, 'edit', array('action' => 'title'));
  }

  // Piwigo invalidates its cache before notifying plugin actions. Invalidate it
  // again after our own database update so the new titles are immediately used.
  invalidate_user_cache();

  $page['infos'][] = sprintf(
    '%d Bildtitel wurden fortlaufend aktualisiert.',
    count($updates)
  );
}

/**
 * Returns selected images in the order requested by the administrator.
 * Returns false if album order was requested without a single current album.
 */
function bratonien_tools_batch_titles_get_images($ids, $sort)
{
  global $conf;

  $id_list = implode(',', array_map('intval', $ids));

  if ($sort === 'album_order')
  {
    if (
      empty($_SESSION['bulk_manager_filter']['category'])
      || isset($_SESSION['bulk_manager_filter']['category_recursive'])
    )
    {
      return false;
    }

    $category_id = (int)$_SESSION['bulk_manager_filter']['category'];
    if ($category_id < 1)
    {
      return false;
    }

    $category_info = get_cat_info($category_id);
    $order_by = $conf['order_by_inside_category'];
    if (!empty($category_info['image_order']))
    {
      $order_by = ' ORDER BY '.$category_info['image_order'];
    }

    $query = '\nSELECT id, name, file, date_creation\n  FROM '.IMAGES_TABLE.'\n  JOIN '.IMAGE_CATEGORY_TABLE.' ON id = image_id\n  WHERE id IN ('.$id_list.')\n    AND category_id = '.$category_id.'\n  '.$order_by.'\n;';
  }
  else
  {
    if ($sort === 'date_creation')
    {
      $order_by = ' ORDER BY (date_creation IS NULL), date_creation ASC, file ASC, id ASC';
    }
    else
    {
      $order_by = ' ORDER BY file ASC, id ASC';
    }

    $query = '\nSELECT id, name, file, date_creation\n  FROM '.IMAGES_TABLE.'\n  WHERE id IN ('.$id_list.')\n  '.$order_by.'\n;';
  }

  $result = pwg_query($query);
  $images = array();
  while ($row = pwg_db_fetch_assoc($result))
  {
    $images[] = $row;
  }

  return $images;
}

/**
 * Conservative detection of empty, filename-derived and common camera/import
 * titles. Meaningful custom titles are left untouched in "camera" mode.
 */
function bratonien_tools_batch_titles_is_replaceable($image)
{
  $title = trim((string)($image['name'] ?? ''));
  if ($title === '')
  {
    return true;
  }

  $file = trim((string)($image['file'] ?? ''));
  $file_base = pathinfo($file, PATHINFO_FILENAME);
  if (
    ($file !== '' && strcasecmp($title, $file) === 0)
    || ($file_base !== '' && strcasecmp($title, $file_base) === 0)
  )
  {
    return true;
  }

  return (bool)preg_match(
    '/^(?:DSC[F]?|IMG|PXL|DCIM|SAM|PIC|PHOTO|IMAGE)[-_ ]?\d+(?:[-_ ].*)?(?:\.[A-Z0-9]{2,5})?$/i',
    $title
  );
}
