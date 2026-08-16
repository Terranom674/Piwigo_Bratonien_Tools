<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_get_public_albums()
{
  $query = "SELECT id, name, uppercats FROM ".CATEGORIES_TABLE." WHERE status = 'public' ORDER BY global_rank ASC, name ASC";
  return query2array($query);
}

function bratonien_tools_lock_album()
{
  global $user;

  $category_id = isset($_POST['lock_category_id']) ? (int)$_POST['lock_category_id'] : 0;
  if ($category_id < 1)
  {
    throw new Exception('Bitte ein öffentliches Album auswählen.');
  }

  $query = 'SELECT id, name, status FROM '.CATEGORIES_TABLE.' WHERE id = '.$category_id.' LIMIT 1';
  $result = pwg_query($query);
  if (!pwg_db_num_rows($result))
  {
    throw new Exception('Das gewählte Album existiert nicht.');
  }

  $album = pwg_db_fetch_assoc($result);
  if ($album['status'] === 'private')
  {
    bratonien_tools_ensure_private_album_access($category_id, (int)$user['id']);
    if (function_exists('invalidate_user_cache'))
    {
      invalidate_user_cache();
    }

    return array('message' => 'Das Album ist bereits privat. Dein Zugriff wurde sichergestellt.');
  }

  if (!function_exists('set_cat_status'))
  {
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
  }

  set_cat_status(array($category_id), 'private');
  bratonien_tools_ensure_private_album_access($category_id, (int)$user['id']);

  if (function_exists('invalidate_user_cache'))
  {
    invalidate_user_cache();
  }

  return array(
    'message' => 'Album „'.$album['name'].'“ wurde privat geschaltet. Dein Benutzer behält Zugriff.',
  );
}
