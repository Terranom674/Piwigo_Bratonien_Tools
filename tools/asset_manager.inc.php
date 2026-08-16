<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_asset_relative_dir()
{
  return 'local/bratonien/assets/';
}

function bratonien_tools_asset_absolute_dir()
{
  return PHPWG_ROOT_PATH . bratonien_tools_asset_relative_dir();
}

function bratonien_tools_ensure_asset_directory()
{
  $dir = bratonien_tools_asset_absolute_dir();
  if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir))
  {
    throw new RuntimeException('Asset-Verzeichnis konnte nicht angelegt werden: '.$dir);
  }

  if (!is_writable($dir))
  {
    throw new RuntimeException('Asset-Verzeichnis ist nicht beschreibbar: '.$dir);
  }

  return $dir;
}

function bratonien_tools_asset_safe_name($name)
{
  $name = basename((string)$name);
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $base = pathinfo($name, PATHINFO_FILENAME);
  $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $base);
  $base = trim($base, '.-_');
  if ($base === '')
  {
    $base = 'bild';
  }
  return $base.'.'.$ext;
}

function bratonien_tools_asset_validate_upload($tmp, $original_name)
{
  $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp');
  $ext = strtolower(pathinfo((string)$original_name, PATHINFO_EXTENSION));
  if (!in_array($ext, $allowed, true))
  {
    throw new RuntimeException('Erlaubte Bildformate: JPG, JPEG, PNG, GIF und WEBP.');
  }

  $info = @getimagesize($tmp);
  if ($info === false)
  {
    throw new RuntimeException('Die hochgeladene Datei ist kein gueltiges Bild.');
  }

  $mime_allowed = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
  if (empty($info['mime']) || !in_array(strtolower($info['mime']), $mime_allowed, true))
  {
    throw new RuntimeException('Der erkannte Bildtyp ist nicht erlaubt.');
  }

  return $info;
}

function bratonien_tools_asset_unique_target($dir, $filename)
{
  $ext = pathinfo($filename, PATHINFO_EXTENSION);
  $base = pathinfo($filename, PATHINFO_FILENAME);
  $target = $dir.$filename;
  $counter = 2;

  while (file_exists($target))
  {
    $filename = $base.'-'.$counter.'.'.$ext;
    $target = $dir.$filename;
    $counter++;
  }

  return array($target, $filename);
}

function bratonien_tools_upload_asset()
{
  if (empty($_FILES['asset_upload']) || !is_array($_FILES['asset_upload']))
  {
    throw new RuntimeException('Keine Bilddatei ausgewaehlt.');
  }

  $file = $_FILES['asset_upload'];
  if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK)
  {
    $code = isset($file['error']) ? (int)$file['error'] : -1;
    if ($code === UPLOAD_ERR_INI_SIZE)
    {
      throw new RuntimeException('Upload abgelehnt: Die Datei ist groesser als das PHP-Limit upload_max_filesize ('.ini_get('upload_max_filesize').').');
    }
    if ($code === UPLOAD_ERR_FORM_SIZE)
    {
      throw new RuntimeException('Upload abgelehnt: Die Datei ueberschreitet das erlaubte Upload-Limit des Formulars.');
    }
    if ($code === UPLOAD_ERR_PARTIAL)
    {
      throw new RuntimeException('Upload fehlgeschlagen: Die Datei wurde nur teilweise uebertragen.');
    }
    throw new RuntimeException('Upload fehlgeschlagen (PHP-Uploadcode '.$code.').');
  }

  bratonien_tools_asset_validate_upload($file['tmp_name'], $file['name']);
  $dir = bratonien_tools_ensure_asset_directory();
  $safe = bratonien_tools_asset_safe_name($file['name']);
  list($target, $filename) = bratonien_tools_asset_unique_target($dir, $safe);

  if (!@move_uploaded_file($file['tmp_name'], $target))
  {
    throw new RuntimeException('Die Bilddatei konnte nicht in das Asset-Verzeichnis verschoben werden.');
  }

  @chmod($target, 0644);
  return array('message' => 'Bild hochgeladen. Relativer Pfad: '.bratonien_tools_asset_relative_dir().$filename);
}

function bratonien_tools_delete_asset()
{
  $filename = isset($_POST['asset_file']) ? basename((string)$_POST['asset_file']) : '';
  if ($filename === '')
  {
    throw new RuntimeException('Keine Datei zum Loeschen ausgewaehlt.');
  }

  $dir = bratonien_tools_asset_absolute_dir();
  $target = $dir.$filename;
  if (!is_file($target))
  {
    throw new RuntimeException('Die Datei wurde nicht gefunden.');
  }

  if (!@unlink($target))
  {
    throw new RuntimeException('Die Datei konnte nicht geloescht werden.');
  }

  return array('message' => 'Bilddatei geloescht: '.$filename);
}

function bratonien_tools_get_assets()
{
  $dir = bratonien_tools_asset_absolute_dir();
  if (!is_dir($dir))
  {
    return array();
  }

  $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp');
  $assets = array();
  foreach (scandir($dir) as $file)
  {
    if ($file === '.' || $file === '..')
    {
      continue;
    }

    $absolute = $dir.$file;
    if (!is_file($absolute))
    {
      continue;
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true))
    {
      continue;
    }

    $info = @getimagesize($absolute);
    $relative = bratonien_tools_asset_relative_dir().$file;
    $assets[] = array(
      'file' => $file,
      'relative' => $relative,
      'url' => get_root_url().$relative,
      'width' => $info ? (int)$info[0] : 0,
      'height' => $info ? (int)$info[1] : 0,
      'bytes' => (int)filesize($absolute),
    );
  }

  usort($assets, function($a, $b) {
    return strcasecmp($a['file'], $b['file']);
  });

  return $assets;
}

function bratonien_tools_upload_user_ini_path()
{
  $filename = trim((string)ini_get('user_ini.filename'));
  if ($filename === '')
  {
    return '';
  }
  return rtrim(PHPWG_ROOT_PATH, '/').'/'.basename($filename);
}

function bratonien_tools_upload_limits_configurable()
{
  $path = bratonien_tools_upload_user_ini_path();
  if ($path === '')
  {
    return false;
  }

  $sapi = strtolower((string)PHP_SAPI);
  if (strpos($sapi, 'fpm') === false && strpos($sapi, 'cgi') === false)
  {
    return false;
  }

  return file_exists($path) ? is_writable($path) : is_writable(PHPWG_ROOT_PATH);
}

function bratonien_tools_save_upload_limits()
{
  if (!bratonien_tools_upload_limits_configurable())
  {
    throw new RuntimeException('Die PHP-Upload-Limits koennen in dieser Serverkonfiguration nicht automatisch ueber .user.ini angepasst werden.');
  }

  $upload_mb = isset($_POST['upload_max_mb']) ? (int)$_POST['upload_max_mb'] : 0;
  $post_mb = isset($_POST['post_max_mb']) ? (int)$_POST['post_max_mb'] : 0;

  if ($upload_mb < 1 || $upload_mb > 1024)
  {
    throw new RuntimeException('Das Datei-Limit muss zwischen 1 und 1024 MB liegen.');
  }
  if ($post_mb < 1 || $post_mb > 2048)
  {
    throw new RuntimeException('Das POST-Limit muss zwischen 1 und 2048 MB liegen.');
  }
  if ($post_mb < $upload_mb)
  {
    throw new RuntimeException('post_max_size muss mindestens so gross wie upload_max_filesize sein.');
  }

  $path = bratonien_tools_upload_user_ini_path();
  $existing = is_file($path) ? (string)@file_get_contents($path) : '';
  $start = '; BEGIN Bratonien Tools upload limits';
  $end = '; END Bratonien Tools upload limits';
  $block = $start."\n"
    .'upload_max_filesize = '.$upload_mb."M\n"
    .'post_max_size = '.$post_mb."M\n"
    .$end;

  $pattern = '/'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'/s';
  if (preg_match($pattern, $existing))
  {
    $updated = preg_replace($pattern, $block, $existing, 1);
  }
  else
  {
    $updated = rtrim($existing).($existing !== '' ? "\n\n" : '').$block."\n";
  }

  if (@file_put_contents($path, $updated, LOCK_EX) === false)
  {
    throw new RuntimeException('Die PHP-Benutzerkonfiguration konnte nicht geschrieben werden: '.$path);
  }

  $ttl = (int)ini_get('user_ini.cache_ttl');
  $suffix = $ttl > 0
    ? ' PHP uebernimmt die neuen Werte spaetestens nach etwa '.$ttl.' Sekunden.'
    : ' Die neuen Werte werden von PHP bei der naechsten Einlesung der Benutzerkonfiguration uebernommen.';

  return array('message' => 'Upload-Limits gespeichert: Datei '.$upload_mb.' MB, POST '.$post_mb.' MB.'.$suffix);
}

function bratonien_tools_ini_size_to_mb($value)
{
  $value = trim((string)$value);
  if ($value === '') return 0;
  $number = (float)$value;
  $unit = strtolower(substr($value, -1));
  if ($unit === 'g') return (int)round($number * 1024);
  if ($unit === 'k') return (int)max(1, round($number / 1024));
  if ($unit === 'm') return (int)round($number);
  return (int)max(1, round($number / 1048576));
}

function bratonien_tools_get_asset_environment()
{
  $absolute = bratonien_tools_asset_absolute_dir();
  $user_ini_path = bratonien_tools_upload_user_ini_path();
  return array(
    'root' => PHPWG_ROOT_PATH,
    'relative_dir' => bratonien_tools_asset_relative_dir(),
    'absolute_dir' => $absolute,
    'exists' => is_dir($absolute),
    'writable' => is_dir($absolute) && is_writable($absolute),
    'upload_max' => ini_get('upload_max_filesize'),
    'post_max' => ini_get('post_max_size'),
    'upload_max_mb' => bratonien_tools_ini_size_to_mb(ini_get('upload_max_filesize')),
    'post_max_mb' => bratonien_tools_ini_size_to_mb(ini_get('post_max_size')),
    'upload_limits_configurable' => bratonien_tools_upload_limits_configurable(),
    'user_ini_path' => $user_ini_path,
    'user_ini_cache_ttl' => (int)ini_get('user_ini.cache_ttl'),
  );
}
