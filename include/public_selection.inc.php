<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Adds a public multi-photo selection mode to album thumbnail pages.
 * The actual ZIP generation remains handled by Batch Downloader.
 */
function bratonien_tools_public_selection_init()
{
  if (defined('IN_ADMIN'))
  {
    return;
  }

  add_event_handler('loc_end_index', 'bratonien_tools_public_selection_render', EVENT_HANDLER_PRIORITY_NEUTRAL + 30);
  add_event_handler('batchdownload_get_set_info', 'bratonien_tools_public_selection_filter_set', EVENT_HANDLER_PRIORITY_NEUTRAL + 30, 1);
}

function bratonien_tools_public_selection_render()
{
  global $page, $template;

  if (!defined('BATCH_DOWNLOAD_PATH') || !function_exists('check_download_access'))
  {
    return;
  }

  if (check_download_access() === false)
  {
    return;
  }

  if (empty($page['items']) || empty($page['section']) || $page['section'] !== 'categories' || empty($page['category']))
  {
    return;
  }

  $template->assign(array(
    'BRATONIEN_SELECTION_PATH' => 'plugins/'.BRATONIEN_TOOLS_ID,
    'BRATONIEN_SELECTION_DOWNLOAD_URL' => bratonien_tools_public_selection_download_url(),
  ));

  $template->set_filename('bratonien_selection_assets', BRATONIEN_TOOLS_PATH.'template/public_selection_assets.tpl');
  $template->parse('bratonien_selection_assets', false);

  $button = '<a href="#" id="bratonien-selection-toggle" class="pwg-icon" title="Bilder auswaehlen">'
    .'<span class="fas fa-check-square" aria-hidden="true"></span>'
    .'<span class="bratonien-selection-toolbar-label">Auswahl</span>'
    .'</a>';

  $template->add_index_button($button, 49);
}

function bratonien_tools_public_selection_download_url()
{
  $url = duplicate_index_url(array(), array('action', 'down_size', 'bratonien_selection'));
  return add_url_params($url, array('action' => 'advdown_set'));
}

/**
 * Restricts the Batch Downloader set to the image IDs selected in the public UI.
 * The intersection with the current Piwigo set prevents downloading images that
 * are not part of the currently accessible album/result set.
 */
function bratonien_tools_public_selection_filter_set($set)
{
  if (!is_array($set))
  {
    return $set;
  }

  if (empty($_GET['action']) || $_GET['action'] !== 'advdown_set' || !isset($_GET['bratonien_selection']))
  {
    return $set;
  }

  $raw = is_array($_GET['bratonien_selection'])
    ? implode(',', $_GET['bratonien_selection'])
    : (string)$_GET['bratonien_selection'];

  $selected = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $raw)))));
  if (empty($selected))
  {
    $set['items'] = array();
    return $set;
  }

  $allowed = array_map('intval', isset($set['items']) && is_array($set['items']) ? $set['items'] : array());
  $allowed_lookup = array_fill_keys($allowed, true);

  $filtered = array();
  foreach ($selected as $image_id)
  {
    if (isset($allowed_lookup[$image_id]))
    {
      $filtered[] = $image_id;
    }
  }

  $set['items'] = $filtered;
  $set['type'] = 'bratonien_selection';
  $set['id'] = isset($set['id']) ? $set['id'] : null;

  return $set;
}

bratonien_tools_public_selection_init();
