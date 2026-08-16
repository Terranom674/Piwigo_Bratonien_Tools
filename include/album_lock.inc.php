<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_get_album_lock_page($page = 1, $per_page = 10)
{
  $per_page = max(1, min(10, (int)$per_page));
  $page = max(1, (int)$page);

  $count_row = pwg_db_fetch_assoc(pwg_query('SELECT COUNT(*) AS total FROM '.CATEGORIES_TABLE));
  $total = $count_row ? (int)$count_row['total'] : 0;
  $pages = max(1, (int)ceil($total / $per_page));
  if ($page > $pages)
  {
    $page = $pages;
  }

  $offset = ($page - 1) * $per_page;
  $query = 'SELECT id, name, uppercats, status FROM '.CATEGORIES_TABLE
    .' ORDER BY global_rank ASC, name ASC'
    .' LIMIT '.$offset.', '.$per_page;

  return array(
    'albums' => query2array($query),
    'page' => $page,
    'pages' => $pages,
    'total' => $total,
    'has_previous' => $page > 1,
    'has_next' => $page < $pages,
    'previous_page' => max(1, $page - 1),
    'next_page' => min($pages, $page + 1),
  );
}

function bratonien_tools_toggle_album_lock()
{
  global $user;

  $category_id = isset($_POST['lock_category_id']) ? (int)$_POST['lock_category_id'] : 0;
  if ($category_id < 1)
  {
    throw new Exception('Ungültiges Album.');
  }

  $query = 'SELECT id, name, status FROM '.CATEGORIES_TABLE.' WHERE id = '.$category_id.' LIMIT 1';
  $result = pwg_query($query);
  if (!pwg_db_num_rows($result))
  {
    throw new Exception('Das gewählte Album existiert nicht.');
  }

  $album = pwg_db_fetch_assoc($result);

  if (!function_exists('set_cat_status'))
  {
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
  }

  if ($album['status'] === 'private')
  {
    set_cat_status(array($category_id), 'public');

    if (function_exists('invalidate_user_cache'))
    {
      invalidate_user_cache();
    }

    return array(
      'message' => 'Album „'.$album['name'].'“ wurde wieder öffentlich geschaltet.',
    );
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
