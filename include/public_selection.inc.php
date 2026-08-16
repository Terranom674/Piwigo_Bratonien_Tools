<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_get_public_selection_settings()
{
  $default = array(
    'enabled' => true,
    'allow_guests' => true,
    'allow_registered' => true,
    'groups' => array(),
  );

  if (!function_exists('conf_get_param'))
  {
    return $default;
  }

  $stored = conf_get_param('bratonien_public_selection', null);
  if (empty($stored))
  {
    return $default;
  }

  $decoded = json_decode($stored, true);
  if (!is_array($decoded))
  {
    return $default;
  }

  $settings = array_merge($default, $decoded);
  $settings['groups'] = array_values(array_unique(array_map('intval', is_array($settings['groups']) ? $settings['groups'] : array())));

  return $settings;
}

function bratonien_tools_save_public_selection_settings()
{
  if (!function_exists('conf_update_param'))
  {
    throw new RuntimeException('Piwigo-Konfiguration ist nicht verfuegbar.');
  }

  $groups = isset($_POST['selection_groups']) && is_array($_POST['selection_groups'])
    ? array_values(array_unique(array_filter(array_map('intval', $_POST['selection_groups']))))
    : array();

  $settings = array(
    'enabled' => !empty($_POST['selection_enabled']),
    'allow_guests' => !empty($_POST['selection_allow_guests']),
    'allow_registered' => !empty($_POST['selection_allow_registered']),
    'groups' => $groups,
  );

  conf_update_param('bratonien_public_selection', json_encode($settings));

  return array('message' => 'Zugriffsrechte fuer die Fotoauswahl gespeichert.');
}

function bratonien_tools_get_piwigo_groups()
{
  if (!defined('GROUPS_TABLE'))
  {
    return array();
  }

  return query2array('SELECT id, name FROM '.GROUPS_TABLE.' ORDER BY name');
}

function bratonien_tools_public_selection_access_allowed()
{
  global $user;

  $settings = bratonien_tools_get_public_selection_settings();
  if (empty($settings['enabled']))
  {
    return false;
  }

  if (function_exists('is_admin') && is_admin())
  {
    return true;
  }

  if (function_exists('is_a_guest') && is_a_guest())
  {
    return !empty($settings['allow_guests']);
  }

  if (!empty($settings['allow_registered']))
  {
    return true;
  }

  if (empty($settings['groups']) || empty($user['id']) || !defined('USER_GROUP_TABLE'))
  {
    return false;
  }

  $query = 'SELECT 1 FROM '.USER_GROUP_TABLE.' WHERE user_id='.(int)$user['id'].' AND group_id IN('.implode(',', $settings['groups']).') LIMIT 1';
  $result = pwg_query($query);

  return pwg_db_num_rows($result) > 0;
}

function bratonien_tools_public_selection_init()
{
  if (defined('IN_ADMIN'))
  {
    return;
  }

  add_event_handler('loc_end_index', 'bratonien_tools_public_selection_download_request', EVENT_HANDLER_PRIORITY_NEUTRAL + 5);
  add_event_handler('loc_end_index', 'bratonien_tools_public_selection_render', EVENT_HANDLER_PRIORITY_NEUTRAL + 30);
  add_event_handler('batchdownload_get_set_info', 'bratonien_tools_public_selection_filter_set', EVENT_HANDLER_PRIORITY_NEUTRAL + 30, 1);
}

function bratonien_tools_public_selection_page_allowed()
{
  global $page;

  if (!defined('BATCH_DOWNLOAD_PATH') || !function_exists('check_download_access'))
  {
    return false;
  }

  if (check_download_access() === false || !bratonien_tools_public_selection_access_allowed())
  {
    return false;
  }

  if (empty($page['items']) || empty($page['section']) || $page['section'] !== 'categories' || empty($page['category']))
  {
    return false;
  }

  if (defined('DLPERMS_PATH') && function_exists('check_album_download_access') && !check_album_download_access($page['category']['id']))
  {
    return false;
  }

  return true;
}

function bratonien_tools_batch_download_init_url($set_id)
{
  $base = rtrim(BATCH_DOWNLOAD_PUBLIC, '/').'/init_zip';
  $separator = strpos($base, '?') === false ? '?' : '&';
  return $base.$separator.'set_id='.(int)$set_id;
}

function bratonien_tools_public_selection_download_request()
{
  global $page, $conf;

  if (empty($_POST['bratonien_selection_download']))
  {
    return;
  }

  header('Content-Type: application/json; charset=utf-8');

  try
  {
    if (!bratonien_tools_public_selection_page_allowed())
    {
      throw new RuntimeException('Download ist fuer diese Auswahl nicht erlaubt.');
    }

    if (function_exists('check_pwg_token'))
    {
      check_pwg_token();
    }

    $raw = isset($_POST['image_ids']) ? (string)$_POST['image_ids'] : '';
    $selected = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $raw)))));
    if (empty($selected))
    {
      throw new RuntimeException('Es wurden keine Bilder ausgewaehlt.');
    }

    $allowed = array_fill_keys(array_map('intval', $page['items']), true);
    $filtered = array();
    foreach ($selected as $image_id)
    {
      if (isset($allowed[$image_id]))
      {
        $filtered[] = $image_id;
      }
    }

    if (empty($filtered))
    {
      throw new RuntimeException('Die Auswahl enthaelt keine herunterladbaren Bilder.');
    }

    if (!class_exists('BatchDownloader'))
    {
      throw new RuntimeException('Batch Downloader ist nicht verfuegbar.');
    }

    $set = new BatchDownloader('new', $filtered, 'category', $page['category']['id'], 'original');
    $set_id = (int)$set->getParam('id');
    if ($set_id < 1 || (int)$set->getParam('nb_images') < 1)
    {
      $set->delete();
      throw new RuntimeException('Fuer die Auswahl konnte kein Download erzeugt werden.');
    }

    if (
      (int)$set->getParam('nb_images') <= (int)$conf['batch_download']['max_elements']
      && $set->getParam('size') === 'original'
      && $set->getEstimatedArchiveNumber() === 1
    )
    {
      $set->createNextArchive(true);
      $target = get_root_url().BATCH_DOWNLOAD_PATH.'download.php?set_id='.$set_id.'&zip=1';
      echo json_encode(array('ok' => true, 'download_url' => $target, 'set_id' => $set_id));
      exit;
    }

    $target = bratonien_tools_batch_download_init_url($set_id);
    echo json_encode(array('ok' => true, 'download_url' => $target, 'set_id' => $set_id));
    exit;
  }
  catch (Throwable $e)
  {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()));
    exit;
  }
}

function bratonien_tools_public_selection_render()
{
  global $template;

  if (!bratonien_tools_public_selection_page_allowed())
  {
    return;
  }

  $template->assign(array(
    'BRATONIEN_SELECTION_PATH' => 'plugins/'.BRATONIEN_TOOLS_ID,
    'BRATONIEN_SELECTION_DOWNLOAD_URL' => duplicate_index_url(),
    'BRATONIEN_SELECTION_TOKEN' => function_exists('get_pwg_token') ? get_pwg_token() : '',
  ));

  $template->set_filename('bratonien_selection_assets', BRATONIEN_TOOLS_PATH.'template/public_selection_assets.tpl');
  $template->parse('bratonien_selection_assets', false);

  $button = '<a href="#" id="bratonien-selection-toggle" class="nav-link" title="Bilder auswählen" aria-label="Bilder auswählen">'
    .'<i class="fas fa-check-square fa-fw" aria-hidden="true"></i>'
    .'<span class="d-lg-none ml-2 text-capitalize">Auswahl</span>'
    .'</a>';

  $template->add_index_button($button, 49);
}

function bratonien_tools_public_selection_download_url()
{
  $url = duplicate_index_url(array(), array('action', 'down_size', 'bratonien_selection'));
  return add_url_params($url, array('action' => 'advdown_set'));
}

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

  if (!bratonien_tools_public_selection_access_allowed())
  {
    $set['items'] = array();
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
