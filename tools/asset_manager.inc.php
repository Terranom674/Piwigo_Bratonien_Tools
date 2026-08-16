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

function bratonien_tools_get_asset_environment()
{
  $absolute = bratonien_tools_asset_absolute_dir();
  return array(
    'root' => PHPWG_ROOT_PATH,
    'relative_dir' => bratonien_tools_asset_relative_dir(),
    'absolute_dir' => $absolute,
    'exists' => is_dir($absolute),
    'writable' => is_dir($absolute) && is_writable($absolute),
    'upload_max' => ini_get('upload_max_filesize'),
    'post_max' => ini_get('post_max_size'),
  );
}
