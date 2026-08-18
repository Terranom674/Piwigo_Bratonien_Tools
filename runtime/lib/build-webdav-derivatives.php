#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

const BRATONIEN_WEBDAV_DERIVATIVE_BUILDER_VERSION = '0.9.5.22';

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
  echo 'WebDAV-Derivative-Builder: '.BRATONIEN_WEBDAV_DERIVATIVE_BUILDER_VERSION."\n";

  $variants = bratonien_tools_webdav_derivative_variants();
  if (!$variants)
  {
    throw new RuntimeException('Keine Piwigo-Derivate konfiguriert.');
  }

  $images = 0;
  $generated = 0;
  $identity = 0;
  $metadata_repaired = 0;
  $errors = 0;

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
      fwrite(STDERR, 'Bild #'.$image_id.": vorbereitetes WebDAV-Preview fehlt.\n");
      continue;
    }

    $size = @getimagesize($preview);
    if (!is_array($size) || empty($size[0]) || empty($size[1]))
    {
      $errors++;
      fwrite(STDERR, 'Bild #'.$image_id.": Abmessungen des WebDAV-Previews konnten nicht gelesen werden.\n");
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
    foreach ($variants as $variant_name => $params)
    {
      try
      {
        $probe = new DerivativeImage($params, $src);
        if ($probe->same_as_source())
        {
          $identity++;
          continue;
        }

        if (bratonien_tools_webdav_generate_derivative($params, $src))
        {
          $generated++;
        }
        else
        {
          $errors++;
          fwrite(STDERR, 'Bild #'.$image_id.' '.$variant_name.": Derivat konnte nicht erzeugt werden.\n");
        }
      }
      catch (Throwable $e)
      {
        $errors++;
        fwrite(STDERR, 'Bild #'.$image_id.' '.$variant_name.': '.$e->getMessage()."\n");
      }
    }
  }

  if ($metadata_repaired > 0)
  {
    update_category('all');
    invalidate_user_cache(true);
  }

  echo 'WebDAV-Derivate: bilder='.$images.
    ' varianten='.count($variants).
    ' erzeugt='.$generated.
    ' identisch='.$identity.
    ' metadaten_repariert='.$metadata_repaired.
    ' fehler='.$errors."\n";
  exit($errors > 0 ? 1 : 0);
}
catch (Throwable $e)
{
  fwrite(STDERR, 'WebDAV-Derivate: '.$e->getMessage()."\n");
  exit(1);
}
