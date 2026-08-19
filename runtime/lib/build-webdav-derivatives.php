#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

const BRATONIEN_WEBDAV_DERIVATIVE_BUILDER_VERSION = '0.9.7.4';

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
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Derivative-Builder/'.BRATONIEN_WEBDAV_DERIVATIVE_BUILDER_VERSION;
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');
require_once(PHPWG_ROOT_PATH.'admin/include/image.class.php');

if (!function_exists('bratonien_tools_webdav_image_source_info') || !function_exists('bratonien_tools_webdav_generate_derivative'))
{
  fwrite(STDERR, "Bratonien WebDAV-Bildruntime ist nicht aktiv.\n");
  exit(1);
}

try
{
  $standard_gallery = ImageStdParams::get_by_type(IMG_THUMB);
  if (!$standard_gallery || !isset($standard_gallery->sizing->ideal_size))
  {
    throw new RuntimeException('Piwigo-Galeriederivat ist nicht konfiguriert.');
  }

  // Only the gallery thumbnail is prebuilt. Keep the Piwigo target type/path,
  // but never crop to the 1x1 placeholder geometry. The real Nextcloud
  // preview dimensions determine the aspect ratio.
  $gallery_params = clone $standard_gallery;
  $gallery_params->sizing = new SizingParams(
    array(
      (int)$standard_gallery->sizing->ideal_size[0],
      (int)$standard_gallery->sizing->ideal_size[1],
    ),
    0,
    null
  );
  $gallery_params->type = IMG_THUMB;
  $gallery_params->use_watermark = false;

  $images = 0;
  $generated_or_ready = 0;
  $identity = 0;
  $metadata_repaired = 0;
  $errors = 0;
  $error_lines = array();

  $result = pwg_query('SELECT * FROM '.IMAGES_TABLE.' ORDER BY id');
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

    $preview_ext = strtolower(pathinfo($preview, PATHINFO_EXTENSION));
    if (!in_array($preview_ext, array('jpg', 'jpeg', 'png', 'gif'), true))
    {
      $errors++;
      $error_lines[] = 'Bild #'.$image_id.': Preview-Format '.$preview_ext.' ist nicht Piwigo-kompatibel; Precache muss neu erzeugt werden.';
      continue;
    }

    $size = @getimagesize($preview);
    if (!is_array($size) || empty($size[0]) || empty($size[1]))
    {
      $errors++;
      $error_lines[] = 'Bild #'.$image_id.': Preview ist kein lesbares Bild: '.$preview;
      continue;
    }

    $preview_width = (int)$size[0];
    $preview_height = (int)$size[1];
    if ((int)($row['width'] ?? 0) !== $preview_width || (int)($row['height'] ?? 0) !== $preview_height || (int)($row['rotation'] ?? 0) !== 0)
    {
      pwg_query(
        'UPDATE '.IMAGES_TABLE.
        ' SET width='.$preview_width.', height='.$preview_height.', rotation=0'.
        ' WHERE id='.$image_id
      );
      $row['width'] = $preview_width;
      $row['height'] = $preview_height;
      $row['rotation'] = 0;
      $metadata_repaired++;
    }

    $src = new SrcImage($row);
    try
    {
      $probe = new DerivativeImage($gallery_params, $src);
      if ($probe->same_as_source())
      {
        $identity++;
        continue;
      }

      $detail = '';
      if (bratonien_tools_webdav_generate_derivative($gallery_params, $src, $detail))
      {
        $generated_or_ready++;
      }
      else
      {
        $errors++;
        $error_lines[] = 'Bild #'.$image_id.' Galerie: '.($detail !== '' ? $detail : 'Galeriederivat konnte nicht erzeugt werden.');
      }
    }
    catch (Throwable $e)
    {
      $errors++;
      $error_lines[] = 'Bild #'.$image_id.' Galerie: '.get_class($e).': '.$e->getMessage();
    }
  }

  if ($metadata_repaired > 0)
  {
    update_category('all');
    invalidate_user_cache(true);
  }

  if ($errors > 0)
  {
    foreach (array_slice($error_lines, 0, 30) as $line)
    {
      fwrite(STDERR, $line."\n");
    }
    if (count($error_lines) > 30)
    {
      fwrite(STDERR, 'Weitere Fehler: '.(count($error_lines) - 30)."\n");
    }
  }

  echo 'WebDAV-Derivative-Builder: version='.BRATONIEN_WEBDAV_DERIVATIVE_BUILDER_VERSION.
    ' bilder='.$images.
    ' varianten=1'.
    ' bereit='.$generated_or_ready.
    ' identisch='.$identity.
    ' metadaten_repariert='.$metadata_repaired.
    ' fehler='.$errors."\n";

  exit($errors > 0 ? 1 : 0);
}
catch (Throwable $e)
{
  fwrite(STDERR, 'WebDAV-Derivate: '.get_class($e).': '.$e->getMessage()."\n");
  exit(1);
}
