<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_shares_table()
{
  return $GLOBALS['prefixeTable'].'bratonien_tools_album_shares';
}

function bratonien_tools_create_album_shares_table()
{
  $table = bratonien_tools_shares_table();
  pwg_query("CREATE TABLE IF NOT EXISTS `$table` (
    id int(11) NOT NULL AUTO_INCREMENT,
    category_id int(11) NOT NULL,
    user_id mediumint(8) unsigned NOT NULL,
    token_hash char(64) NOT NULL,
    password_hash varchar(255) NOT NULL,
    created_by mediumint(8) unsigned NOT NULL,
    created_at datetime NOT NULL,
    expires_at datetime DEFAULT NULL,
    active tinyint(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY token_hash (token_hash),
    KEY category_id (category_id),
    KEY user_id (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function bratonien_tools_drop_album_shares_table()
{
  pwg_query('DROP TABLE IF EXISTS `'.bratonien_tools_shares_table().'`');
}

/**
 * Secret used to derive stable, non-guessable share tokens without storing the
 * raw token in the database. This makes links reproducible for the admin UI.
 */
function bratonien_tools_share_secret()
{
  $key = 'bratonien_album_share_secret';
  $secret = function_exists('conf_get_param') ? (string)conf_get_param($key, '') : '';

  if ($secret === '')
  {
    $secret = bin2hex(random_bytes(32));
    if (function_exists('conf_update_param'))
    {
      conf_update_param($key, $secret);
    }
  }

  return $secret;
}

function bratonien_tools_share_token($user_id, $category_id)
{
  return substr(
    hash_hmac('sha256', 'share:'.(int)$user_id.':'.(int)$category_id, bratonien_tools_share_secret()),
    0,
    48
  );
}

function bratonien_tools_share_url($token)
{
  return get_absolute_root_url().'?brshare='.$token;
}

function bratonien_tools_album_shares_init()
{
  if (!isset($_GET['brshare']))
  {
    return;
  }

  $token = strtolower(trim((string)$_GET['brshare']));
  if (!preg_match('/^[a-f0-9]{48}$/', $token))
  {
    bratonien_tools_share_access_page('Ungültiger Freigabelink.');
  }

  $share = bratonien_tools_get_share_by_token($token);
  if (!$share)
  {
    bratonien_tools_share_access_page('Diese Freigabe existiert nicht oder wurde widerrufen.');
  }

  if (!empty($share['expires_at']) && strtotime($share['expires_at']) < time())
  {
    bratonien_tools_share_access_page('Diese Freigabe ist abgelaufen.');
  }

  $password_required = !empty($share['password_hash']);
  $session_key = 'bratonien_share_'.(int)$share['id'];
  $authorized = !$password_required || !empty($_SESSION[$session_key]);
  $error = '';

  if ($password_required && !$authorized && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bratonien_share_password']))
  {
    if (password_verify((string)$_POST['bratonien_share_password'], $share['password_hash']))
    {
      $_SESSION[$session_key] = true;
      $authorized = true;
    }
    else
    {
      $error = 'Das Passwort ist nicht korrekt.';
    }
  }

  if (!$authorized)
  {
    bratonien_tools_share_access_page($error, $token, true);
  }

  if (function_exists('is_a_guest') && is_a_guest())
  {
    log_user((int)$share['user_id'], false);
  }

  redirect(PHPWG_ROOT_PATH.'index.php?/category/'.(int)$share['category_id']);
}

function bratonien_tools_get_share_by_token($token)
{
  $hash = hash('sha256', $token);
  $query = 'SELECT s.*, c.name AS category_name FROM '.bratonien_tools_shares_table().' s '
    .'JOIN '.CATEGORIES_TABLE.' c ON c.id = s.category_id '
    ."WHERE s.token_hash = '".pwg_db_real_escape_string($hash)."' AND s.active = 1 LIMIT 1";
  $result = pwg_query($query);
  return pwg_db_num_rows($result) ? pwg_db_fetch_assoc($result) : null;
}

function bratonien_tools_get_album_shares()
{
  $query = 'SELECT s.*, c.name AS category_name, u.username AS created_by_name '
    .'FROM '.bratonien_tools_shares_table().' s '
    .'LEFT JOIN '.CATEGORIES_TABLE.' c ON c.id = s.category_id '
    .'LEFT JOIN '.USERS_TABLE.' u ON u.id = s.created_by '
    .'ORDER BY s.created_at DESC';
  $shares = query2array($query);

  foreach ($shares as &$share)
  {
    $token = bratonien_tools_share_token((int)$share['user_id'], (int)$share['category_id']);
    $expected_hash = hash('sha256', $token);
    $share['password_protected'] = !empty($share['password_hash']);
    $share['link_copyable'] = hash_equals((string)$share['token_hash'], $expected_hash);
    $share['share_url'] = $share['link_copyable'] ? bratonien_tools_share_url($token) : '';
  }
  unset($share);

  return $shares;
}

function bratonien_tools_get_private_albums()
{
  $query = "SELECT id, name, uppercats FROM ".CATEGORIES_TABLE." WHERE status = 'private' ORDER BY global_rank ASC, name ASC";
  return query2array($query);
}

function bratonien_tools_create_album_share()
{
  global $user;

  $category_id = isset($_POST['share_category_id']) ? (int)$_POST['share_category_id'] : 0;
  $password = (string)($_POST['share_password'] ?? '');
  $expires = trim((string)($_POST['share_expires_at'] ?? ''));

  if ($category_id < 1)
  {
    throw new Exception('Bitte ein privates Album auswählen.');
  }

  $query = 'SELECT id FROM '.CATEGORIES_TABLE." WHERE id = $category_id AND status = 'private' LIMIT 1";
  if (pwg_db_num_rows(pwg_query($query)) === 0)
  {
    throw new Exception('Das gewählte Album ist nicht privat oder existiert nicht.');
  }

  $expires_at = null;
  if ($expires !== '')
  {
    $ts = strtotime($expires);
    if ($ts === false || $ts <= time())
    {
      throw new Exception('Das Ablaufdatum muss in der Zukunft liegen.');
    }
    $expires_at = date('Y-m-d H:i:s', $ts);
  }

  $username = 'brshare_'.bin2hex(random_bytes(5));
  $random_password = bin2hex(random_bytes(16));
  $errors = array();
  $new_user_id = register_user($username, $random_password, null, 0, $errors, false);
  if (!$new_user_id || !empty($errors))
  {
    throw new Exception('Der Freigabebenutzer konnte nicht erstellt werden.');
  }

  if (defined('USER_GROUP_TABLE'))
  {
    pwg_query('DELETE FROM '.USER_GROUP_TABLE.' WHERE user_id = '.(int)$new_user_id);
  }

  $level = 0;
  $level_query = 'SELECT MAX(i.level) AS max_level FROM '.IMAGES_TABLE.' i '
    .'JOIN '.IMAGE_CATEGORY_TABLE.' ic ON ic.image_id = i.id '
    .'WHERE ic.category_id = '.$category_id;
  $level_row = pwg_db_fetch_assoc(pwg_query($level_query));
  if ($level_row && $level_row['max_level'] !== null)
  {
    $level = (int)$level_row['max_level'];
  }

  pwg_query('UPDATE '.USER_INFOS_TABLE." SET status = 'generic', level = ".$level.' WHERE user_id = '.(int)$new_user_id);
  bratonien_tools_grant_album_access((int)$new_user_id, $category_id);

  $token = bratonien_tools_share_token((int)$new_user_id, $category_id);
  $token_hash = hash('sha256', $token);
  $password_hash = $password === '' ? '' : password_hash($password, PASSWORD_DEFAULT);
  $expires_sql = $expires_at === null ? 'NULL' : "'".pwg_db_real_escape_string($expires_at)."'";

  $query = 'INSERT INTO '.bratonien_tools_shares_table()
    .' (category_id, user_id, token_hash, password_hash, created_by, created_at, expires_at, active) VALUES ('
    .(int)$category_id.', '.(int)$new_user_id.", '".pwg_db_real_escape_string($token_hash)."', '"
    .pwg_db_real_escape_string($password_hash)."', ".(int)$user['id'].', NOW(), '.$expires_sql.', 1)';
  pwg_query($query);

  invalidate_user_cache();

  return array(
    'message' => ($password === '' ? 'Albumfreigabe' : 'Passwortgeschützte Albumfreigabe').' erstellt: '.bratonien_tools_share_url($token),
  );
}

/**
 * Legacy shares created before reproducible tokens cannot expose their old raw
 * token because only its hash was stored. Regeneration intentionally replaces
 * that old token so the link becomes copyable from the admin UI afterwards.
 */
function bratonien_tools_regenerate_album_share_link()
{
  $share_id = isset($_POST['share_id']) ? (int)$_POST['share_id'] : 0;
  if ($share_id < 1)
  {
    throw new Exception('Ungültige Freigabe.');
  }

  $query = 'SELECT id, category_id, user_id FROM '.bratonien_tools_shares_table().' WHERE id = '.$share_id.' AND active = 1 LIMIT 1';
  $result = pwg_query($query);
  if (!pwg_db_num_rows($result))
  {
    throw new Exception('Freigabe nicht gefunden.');
  }

  $share = pwg_db_fetch_assoc($result);
  $token = bratonien_tools_share_token((int)$share['user_id'], (int)$share['category_id']);
  $token_hash = hash('sha256', $token);

  pwg_query(
    'UPDATE '.bratonien_tools_shares_table()." SET token_hash = '".pwg_db_real_escape_string($token_hash)."' WHERE id = ".$share_id.' LIMIT 1'
  );

  return array(
    'message' => 'Neuer Freigabelink erzeugt: '.bratonien_tools_share_url($token),
  );
}

function bratonien_tools_revoke_album_share()
{
  $share_id = isset($_POST['share_id']) ? (int)$_POST['share_id'] : 0;
  if ($share_id < 1)
  {
    throw new Exception('Ungültige Freigabe.');
  }

  $query = 'SELECT user_id FROM '.bratonien_tools_shares_table().' WHERE id = '.$share_id.' LIMIT 1';
  $result = pwg_query($query);
  if (!pwg_db_num_rows($result))
  {
    throw new Exception('Freigabe nicht gefunden.');
  }
  $row = pwg_db_fetch_assoc($result);
  bratonien_tools_delete_share_user((int)$row['user_id']);
  pwg_query('DELETE FROM '.bratonien_tools_shares_table().' WHERE id = '.$share_id.' LIMIT 1');
  invalidate_user_cache();

  return array('message' => 'Albumfreigabe wurde widerrufen.');
}

function bratonien_tools_delete_share_user($user_id)
{
  $user_id = (int)$user_id;
  if ($user_id < 1)
  {
    return;
  }

  pwg_query('DELETE FROM '.USER_ACCESS_TABLE.' WHERE user_id = '.$user_id);
  if (defined('USER_GROUP_TABLE'))
  {
    pwg_query('DELETE FROM '.USER_GROUP_TABLE.' WHERE user_id = '.$user_id);
  }
  pwg_query('DELETE FROM '.USER_INFOS_TABLE.' WHERE user_id = '.$user_id);
  pwg_query('DELETE FROM '.USERS_TABLE.' WHERE id = '.$user_id);
  if (function_exists('delete_user_sessions'))
  {
    delete_user_sessions($user_id);
  }
}

function bratonien_tools_grant_album_access($user_id, $category_id)
{
  $user_id = (int)$user_id;
  $category_id = (int)$category_id;
  $query = 'SELECT 1 FROM '.USER_ACCESS_TABLE.' WHERE user_id = '.$user_id.' AND cat_id = '.$category_id.' LIMIT 1';
  if (pwg_db_num_rows(pwg_query($query)) === 0)
  {
    pwg_query('INSERT INTO '.USER_ACCESS_TABLE.' (user_id, cat_id) VALUES ('.$user_id.', '.$category_id.')');
  }
}

function bratonien_tools_ensure_private_album_access($category_id, $user_id = null)
{
  global $user;
  $uid = $user_id === null ? (int)$user['id'] : (int)$user_id;
  if ($uid < 1 || (int)$category_id < 1)
  {
    return;
  }
  bratonien_tools_grant_album_access($uid, (int)$category_id);
}

function bratonien_tools_preserve_private_album_access()
{
  global $user;

  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($user['id']))
  {
    return;
  }

  $category_ids = array();
  $private_intent = false;

  if (
    defined('IN_ADMIN')
    && (string)($_GET['page'] ?? '') === 'cat_options'
    && (string)($_GET['section'] ?? '') === 'status'
    && isset($_POST['falsify'])
    && !empty($_POST['cat_true'])
    && is_array($_POST['cat_true'])
  )
  {
    $category_ids = $_POST['cat_true'];
    $private_intent = true;
  }

  foreach (array('status', 'privacy', 'visibility') as $field)
  {
    if (isset($_POST[$field]) && strtolower((string)$_POST[$field]) === 'private')
    {
      $private_intent = true;
    }
  }

  if (isset($_POST['category_id']))
  {
    $category_ids[] = $_POST['category_id'];
  }
  if (isset($_POST['cat_id']))
  {
    $category_ids[] = $_POST['cat_id'];
  }
  if (isset($_POST['category_ids']) && is_array($_POST['category_ids']))
  {
    $category_ids = array_merge($category_ids, $_POST['category_ids']);
  }

  if (!$private_intent || empty($category_ids))
  {
    return;
  }

  $ids = array_values(array_unique(array_filter(array_map('intval', $category_ids), function ($id) {
    return $id > 0;
  })));
  if (empty($ids))
  {
    return;
  }

  $user_id = (int)$user['id'];

  register_shutdown_function(function () use ($ids, $user_id) {
    foreach ($ids as $category_id)
    {
      $query = 'SELECT status FROM '.CATEGORIES_TABLE.' WHERE id = '.(int)$category_id.' LIMIT 1';
      $result = pwg_query($query);
      if (!pwg_db_num_rows($result))
      {
        continue;
      }

      $row = pwg_db_fetch_assoc($result);
      if ($row['status'] === 'private')
      {
        bratonien_tools_grant_album_access($user_id, (int)$category_id);
      }
    }

    if (function_exists('invalidate_user_cache'))
    {
      invalidate_user_cache();
    }
  });
}

function bratonien_tools_album_shares_on_delete_categories($category_ids)
{
  foreach ((array)$category_ids as $category_id)
  {
    $result = pwg_query('SELECT id, user_id FROM '.bratonien_tools_shares_table().' WHERE category_id = '.(int)$category_id);
    while ($row = pwg_db_fetch_assoc($result))
    {
      bratonien_tools_delete_share_user((int)$row['user_id']);
      pwg_query('DELETE FROM '.bratonien_tools_shares_table().' WHERE id = '.(int)$row['id'].' LIMIT 1');
    }
  }
}

function bratonien_tools_share_access_page($error = '', $token = '', $show_form = false)
{
  header('Content-Type: text/html; charset=UTF-8');
  $action = htmlspecialchars(bratonien_tools_share_url($token), ENT_QUOTES, 'UTF-8');
  $message = $error !== '' ? '<p class="brshare-error">'.htmlspecialchars($error, ENT_QUOTES, 'UTF-8').'</p>' : '';
  $form = '';
  if ($show_form)
  {
    $form = '<form method="post" action="'.$action.'"><label for="brshare-password">Passwort</label><input id="brshare-password" type="password" name="bratonien_share_password" required autofocus><button type="submit">Album öffnen</button></form>';
  }

  echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Geschützte Albumfreigabe</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#202428;color:#eee;font-family:system-ui,sans-serif}.brshare{width:min(92vw,460px);padding:28px;border:1px solid #555;background:#2b3035;box-shadow:0 12px 30px rgba(0,0,0,.35)}h1{font-size:1.4rem;margin:0 0 14px}p{line-height:1.5}.brshare-error{color:#ffb4b4}label{display:block;margin:16px 0 6px}input{box-sizing:border-box;width:100%;padding:11px;border:1px solid #666;background:#181b1e;color:#fff}button{margin-top:14px;padding:10px 16px;cursor:pointer}</style></head><body><main class="brshare"><h1>Geschütztes Album</h1><p>Für diese Freigabe ist ein Passwort erforderlich.</p>'.$message.$form.'</main></body></html>';
  exit;
}
