<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_friendship_code_table()
{
  return $GLOBALS['prefixeTable'].'bratonien_tools_friendship_codes';
}

function bratonien_tools_friendship_code_storage_root()
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-friendship-codes';
}

function bratonien_tools_friendship_code_ensure_storage()
{
  $table = bratonien_tools_friendship_code_table();
  pwg_query("CREATE TABLE IF NOT EXISTS `$table` (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    person_name varchar(190) NOT NULL,
    normalized_name varchar(190) NOT NULL,
    original_name varchar(255) NOT NULL,
    stored_name varchar(255) NOT NULL,
    mime_type varchar(100) NOT NULL,
    file_size bigint(20) unsigned NOT NULL DEFAULT 0,
    file_sha256 char(64) NOT NULL,
    created datetime NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY normalized_name (normalized_name),
    UNIQUE KEY file_sha256 (file_sha256)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $root = bratonien_tools_friendship_code_storage_root();
  if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root))
  {
    throw new RuntimeException('Verzeichnis für Freundschaftscodes konnte nicht angelegt werden.');
  }

  return $root;
}

function bratonien_tools_friendship_code_name($value)
{
  $name = trim((string)$value);
  $name = preg_replace('/\s+/u', ' ', $name);
  if ($name === '')
  {
    throw new InvalidArgumentException('Bitte einen Namen eingeben.');
  }

  $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
  if ($length > 120)
  {
    throw new InvalidArgumentException('Der Name darf höchstens 120 Zeichen lang sein.');
  }

  if (preg_match('/[\x00-\x1F\x7F]/u', $name))
  {
    throw new InvalidArgumentException('Der Name enthält ungültige Steuerzeichen.');
  }

  return $name;
}

function bratonien_tools_friendship_code_normalized_name($value)
{
  $name = bratonien_tools_friendship_code_name($value);
  return function_exists('mb_strtolower')
    ? mb_strtolower($name, 'UTF-8')
    : strtolower($name);
}

function bratonien_tools_friendship_code_exists_for_name($name)
{
  bratonien_tools_friendship_code_ensure_storage();
  $normalized = bratonien_tools_friendship_code_normalized_name($name);
  $table = bratonien_tools_friendship_code_table();
  $query = "SELECT id FROM `$table` WHERE normalized_name='"
    .pwg_db_real_escape_string($normalized)."' LIMIT 1";
  return pwg_db_num_rows(pwg_query($query)) > 0;
}

function bratonien_tools_friendship_code_validate_image(array $file)
{
  if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
  {
    throw new RuntimeException('Datei-Upload fehlgeschlagen (Code '.(int)($file['error'] ?? UPLOAD_ERR_NO_FILE).').');
  }
  if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']))
  {
    throw new RuntimeException('Die hochgeladene Datei konnte nicht verifiziert werden.');
  }

  $image = @getimagesize($file['tmp_name']);
  $mime = is_array($image) && !empty($image['mime']) ? strtolower((string)$image['mime']) : '';
  $extensions = array(
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
  );
  if (!isset($extensions[$mime]))
  {
    throw new RuntimeException('Erlaubt sind PNG, JPG, WEBP und GIF.');
  }

  return array(
    'mime' => $mime,
    'extension' => $extensions[$mime],
  );
}

function bratonien_tools_friendship_code_unique_name($extension)
{
  try
  {
    $suffix = bin2hex(random_bytes(8));
  }
  catch (Throwable $e)
  {
    $suffix = str_replace('.', '', uniqid('', true));
  }
  return 'friendship-'.$suffix.'.'.$extension;
}

function bratonien_tools_friendship_code_process_upload($name, array $file)
{
  $name = bratonien_tools_friendship_code_name($name);
  $normalized = bratonien_tools_friendship_code_normalized_name($name);
  $root = bratonien_tools_friendship_code_ensure_storage();
  $table = bratonien_tools_friendship_code_table();

  $lock_dir = $root.DIRECTORY_SEPARATOR.'.locks';
  if (!is_dir($lock_dir) && !@mkdir($lock_dir, 0770, true) && !is_dir($lock_dir))
  {
    throw new RuntimeException('Lockverzeichnis für Freundschaftscodes konnte nicht angelegt werden.');
  }

  $lock_path = $lock_dir.DIRECTORY_SEPARATOR.sha1($normalized).'.lock';
  $lock = @fopen($lock_path, 'c+');
  if ($lock === false || !@flock($lock, LOCK_EX))
  {
    if (is_resource($lock)) fclose($lock);
    throw new RuntimeException('Der Freundschaftscode konnte nicht sicher reserviert werden.');
  }

  try
  {
    if (bratonien_tools_friendship_code_exists_for_name($name))
    {
      return array(
        'status' => 'duplicate',
        'kind' => 'friendship',
        'name' => $name,
        'file' => trim((string)($file['name'] ?? '')),
        'message' => 'Für diesen Namen ist bereits ein Freundschaftscode hinterlegt.',
      );
    }

    $image = bratonien_tools_friendship_code_validate_image($file);
    $sha256 = @hash_file('sha256', $file['tmp_name']);
    if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/', $sha256))
    {
      throw new RuntimeException('Der Freundschaftscode konnte nicht eindeutig geprüft werden.');
    }

    $hash_query = "SELECT person_name FROM `$table` WHERE file_sha256='"
      .pwg_db_real_escape_string($sha256)."' LIMIT 1";
    $hash_result = pwg_query($hash_query);
    if (pwg_db_num_rows($hash_result) > 0)
    {
      $existing = pwg_db_fetch_assoc($hash_result);
      return array(
        'status' => 'duplicate',
        'kind' => 'friendship',
        'name' => $name,
        'file' => trim((string)($file['name'] ?? '')),
        'message' => 'Dieser Freundschaftscode wurde bereits hinterlegt'.(!empty($existing['person_name']) ? ' ('.(string)$existing['person_name'].')' : '').'.',
      );
    }

    $stored_name = bratonien_tools_friendship_code_unique_name($image['extension']);
    $target = $root.DIRECTORY_SEPARATOR.$stored_name;
    if (!@move_uploaded_file($file['tmp_name'], $target))
    {
      throw new RuntimeException('Die Datei konnte nicht dauerhaft gespeichert werden.');
    }
    @chmod($target, 0660);

    $original_name = trim((string)($file['name'] ?? ''));
    if ($original_name === '')
    {
      $original_name = 'Freundschaftscode.'.$image['extension'];
    }

    $query = "INSERT INTO `$table` (person_name, normalized_name, original_name, stored_name, mime_type, file_size, file_sha256, created) VALUES ('"
      .pwg_db_real_escape_string($name)."', '"
      .pwg_db_real_escape_string($normalized)."', '"
      .pwg_db_real_escape_string($original_name)."', '"
      .pwg_db_real_escape_string($stored_name)."', '"
      .pwg_db_real_escape_string($image['mime'])."', "
      .max(0, (int)($file['size'] ?? 0)).", '"
      .pwg_db_real_escape_string($sha256)."', NOW())";

    if (pwg_query($query) === false)
    {
      @unlink($target);
      throw new RuntimeException('Der Freundschaftscode konnte nicht in der Liste gespeichert werden.');
    }

    return array(
      'status' => 'ok',
      'kind' => 'friendship',
      'name' => $name,
      'file' => $original_name,
      'message' => 'Freundschaftscode erfolgreich gespeichert.',
    );
  }
  finally
  {
    @flock($lock, LOCK_UN);
    fclose($lock);
  }
}

function bratonien_tools_friendship_code_delete_upload()
{
  $id = isset($_POST['friendship_code_id']) ? (int)$_POST['friendship_code_id'] : 0;
  if ($id < 1)
  {
    throw new InvalidArgumentException('Ungültiger Freundschaftscode.');
  }

  $root = bratonien_tools_friendship_code_ensure_storage();
  $table = bratonien_tools_friendship_code_table();
  $result = pwg_query('SELECT id, person_name, normalized_name, stored_name FROM `'.$table.'` WHERE id='.$id.' LIMIT 1');
  $row = pwg_db_fetch_assoc($result);
  if (!$row)
  {
    throw new RuntimeException('Der Freundschaftscode wurde nicht gefunden.');
  }

  $stored_name = (string)$row['stored_name'];
  if ($stored_name === '' || basename($stored_name) !== $stored_name)
  {
    throw new RuntimeException('Der gespeicherte Dateiname ist ungültig.');
  }

  $normalized = (string)$row['normalized_name'];
  $lock_dir = $root.DIRECTORY_SEPARATOR.'.locks';
  if (!is_dir($lock_dir) && !@mkdir($lock_dir, 0770, true) && !is_dir($lock_dir))
  {
    throw new RuntimeException('Lockverzeichnis für Freundschaftscodes konnte nicht angelegt werden.');
  }

  $lock = @fopen($lock_dir.DIRECTORY_SEPARATOR.sha1($normalized).'.lock', 'c+');
  if ($lock === false || !@flock($lock, LOCK_EX))
  {
    if (is_resource($lock)) fclose($lock);
    throw new RuntimeException('Der Freundschaftscode konnte nicht sicher gesperrt werden.');
  }

  try
  {
    $file = $root.DIRECTORY_SEPARATOR.$stored_name;
    if (is_file($file) && !@unlink($file))
    {
      throw new RuntimeException('Die Datei konnte nicht gelöscht werden. Der Datenbankeintrag bleibt erhalten.');
    }

    if (pwg_query('DELETE FROM `'.$table.'` WHERE id='.$id.' LIMIT 1') === false)
    {
      throw new RuntimeException('Der Datenbankeintrag konnte nicht gelöscht werden.');
    }
  }
  finally
  {
    @flock($lock, LOCK_UN);
    fclose($lock);
  }

  return array(
    'message' => 'Freundschaftscode von '.(string)$row['person_name'].' gelöscht.',
  );
}

function bratonien_tools_friendship_code_admin_data()
{
  bratonien_tools_friendship_code_ensure_storage();
  $table = bratonien_tools_friendship_code_table();
  $uploads = array();

  $result = pwg_query(
    'SELECT id, person_name, original_name, file_size, created FROM `'.$table.'` ORDER BY person_name ASC, id ASC'
  );
  while ($row = pwg_db_fetch_assoc($result))
  {
    $id = (int)$row['id'];
    $base_url = get_absolute_root_url(true).'plugins/'.BRATONIEN_TOOLS_ID.'/friendship-code-admin-file.php?id='.$id;
    $uploads[] = array(
      'id' => $id,
      'name' => (string)$row['person_name'],
      'original_name' => (string)$row['original_name'],
      'file_size' => (int)$row['file_size'],
      'file_size_label' => function_exists('bratonien_tools_customer_qr_size_label')
        ? bratonien_tools_customer_qr_size_label($row['file_size'])
        : (string)((int)$row['file_size']).' B',
      'created' => (string)$row['created'],
      'preview_url' => $base_url,
      'download_url' => $base_url.'&download=1',
    );
  }

  return array(
    'total' => count($uploads),
    'uploads' => $uploads,
  );
}
