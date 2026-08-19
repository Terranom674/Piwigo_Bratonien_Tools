#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$options = getopt('', array('piwigo-root:', 'connection-id:'));
$piwigo_root = rtrim((string)($options['piwigo-root'] ?? ''), '/');
$connection_id = (int)($options['connection-id'] ?? 0);
if ($piwigo_root === '' || $connection_id < 1)
{
  fwrite(STDERR, "Parameter --piwigo-root und --connection-id werden benoetigt.\n");
  exit(1);
}
if (!is_dir($piwigo_root))
{
  fwrite(STDERR, "Piwigo-Root wurde nicht gefunden.\n");
  exit(1);
}

define('PHPWG_ROOT_PATH', $piwigo_root.'/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/lib/build-webdav-derivatives.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');

if (!function_exists('bratonien_tools_webdav_image_source_info') || !function_exists('bratonien_tools_webdav_preview_path'))
{
  fwrite(STDERR, "Bratonien WebDAV-Bildruntime ist nicht aktiv.\n");
  exit(1);
}

try
{
  $images = 0;
  $updated = 0;
  $errors = 0;
  $error_lines = array();

  $result = pwg_query('SELECT id, width, height, rotation FROM '.IMAGES_TABLE.' ORDER BY id');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $image_id = (int)$row['id'];
    $info = bratonien_tools_webdav_image_source_info($image_id);
    if (!$info || (int)$info['connection_id'] !== $connection_id) continue;

    $images++;
    $preview = bratonien_tools_webdav_preview_path($info);
    if (!$preview)
    {
      $errors++;
      $error_lines[] = 'Bild #'.$image_id.': vorbereitetes WebDAV-Preview fehlt.';
      continue;
    }

    $size = @getimagesize($preview);
    if (!is_array($size) || empty($size[0]) || empty($size[1]))
    {
      $errors++;
      $error_lines[] = 'Bild #'.$image_id.': Preview ist kein lesbares Bild: '.$preview;
      continue;
    }

    $width = (int)$size[0];
    $height = (int)$size[1];
    if ((int)($row['width'] ?? 0) === $width && (int)($row['height'] ?? 0) === $height && (int)($row['rotation'] ?? 0) === 0)
    {
      continue;
    }

    pwg_query(
      'UPDATE '.IMAGES_TABLE.
      ' SET width='.$width.', height='.$height.', rotation=0'.
      ' WHERE id='.$image_id
    );
    $updated++;
  }

  if ($updated > 0)
  {
    update_category('all');
    invalidate_user_cache(true);
  }

  foreach (array_slice($error_lines, 0, 30) as $line)
  {
    fwrite(STDERR, $line."\n");
  }
  if (count($error_lines) > 30)
  {
    fwrite(STDERR, 'Weitere Fehler: '.(count($error_lines) - 30)."\n");
  }

  echo 'WebDAV-Metadaten: bilder='.$images.' aktualisiert='.$updated.' fehler='.$errors."\n";
  exit($errors > 0 ? 1 : 0);
}
catch (Throwable $e)
{
  fwrite(STDERR, 'WebDAV-Metadaten: '.get_class($e).': '.$e->getMessage()."\n");
  exit(1);
}
