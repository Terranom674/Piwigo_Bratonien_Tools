#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

const BRATONIEN_WEBDAV_DERIVATIVE_BUILDER_VERSION = '0.9.7.8';

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

function bratonien_tools_webdav_modus_config()
{
  global $conf;

  $modus = $conf['modus_theme'] ?? array();
  if (is_string($modus) && $modus !== '')
  {
    $decoded = @unserialize($modus);
    if (is_array($decoded)) $modus = $decoded;
  }
  if (!is_array($modus)) $modus = array();

  return array_merge(
    array(
      'index_photo_deriv'=>'2small',
      'index_photo_deriv_hdpi'=>'xsmall',
    ),
    $modus
  );
}

function bratonien_tools_webdav_gallery_base_params()
{
  $modus = bratonien_tools_webdav_modus_config();
  $type = trim((string)($modus['index_photo_deriv'] ?? '2small'));
  $params = $type !== '' ? @ImageStdParams::get_by_type($type) : null;
  if ($params) return $params;

  $params = ImageStdParams::get_by_type(IMG_THUMB);
  if (!$params)
  {
    throw new RuntimeException('Piwigo-Galeriederivat ist nicht konfiguriert.');
  }
  return $params;
}

function bratonien_tools_webdav_modus_gallery_params(SrcImage $src, $base_params)
{
  $row_height = (int)$base_params->max_height();
  $candidates = array($base_params);

  foreach (ImageStdParams::get_defined_type_map() as $params)
  {
    if (
      $params->max_height() > $row_height
      && $params->sizing->max_crop == $base_params->sizing->max_crop
    )
    {
      $candidates[] = $params;
      if (count($candidates) === 3) break;
    }
  }

  $selected = $base_params;
  foreach ($candidates as $params)
  {
    $selected = $params;
    $probe = new DerivativeImage($params, $src);
    $size = $probe->get_size();
    if ((int)$size[1] >= $row_height - 2) break;
  }

  return $selected;
}

try
{
  $gallery_base_params = bratonien_tools_webdav_gallery_base_params();
  $gallery_base_type = (string)$gallery_base_params->type;

  $images = 0;
  $generated_or_ready = 0;
  $identity = 0;
  $metadata_repaired = 0;
  $legacy_derivatives_rebuilt = 0;
  $errors = 0;
  $error_lines = array();
  $variant_counts = array();

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
      $gallery_params = bratonien_tools_webdav_modus_gallery_params($src, $gallery_base_params);
      $variant = (string)$gallery_params->type;
      if ($variant === '') $variant = 'custom';
      $variant_counts[$variant] = ($variant_counts[$variant] ?? 0) + 1;

      $probe = new DerivativeImage($gallery_params, $src);
      if ($probe->same_as_source())
      {
        $identity++;
        continue;
      }

      $target = $probe->get_path();
      if ($target !== '' && is_file($target) && is_readable($target))
      {
        $expected_size = $gallery_params->compute_final_size(array($preview_width, $preview_height));
        $existing_size = @getimagesize($target);
        if (
          is_array($expected_size)
          && isset($expected_size[0], $expected_size[1])
          && is_array($existing_size)
          && isset($existing_size[0], $existing_size[1])
          && ((int)$existing_size[0] !== (int)$expected_size[0] || (int)$existing_size[1] !== (int)$expected_size[1])
        )
        {
          if (!@unlink($target))
          {
            $errors++;
            $error_lines[] = 'Bild #'.$image_id.' Galerie: falsches Alt-Derivat konnte nicht entfernt werden: '.$target;
            continue;
          }
          $legacy_derivatives_rebuilt++;
        }
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

  ksort($variant_counts);
  $variant_parts = array();
  foreach ($variant_counts as $type=>$count)
  {
    $variant_parts[] = $type.':'.$count;
  }

  echo 'WebDAV-Derivative-Builder: version='.BRATONIEN_WEBDAV_DERIVATIVE_BUILDER_VERSION.
    ' bilder='.$images.
    ' varianten_pro_bild=1'.
    ' modus_basis='.$gallery_base_type.
    ' modus_varianten='.implode(',', $variant_parts).
    ' bereit='.$generated_or_ready.
    ' identisch='.$identity.
    ' metadaten_repariert='.$metadata_repaired.
    ' alt_derivate_neu='.$legacy_derivatives_rebuilt.
    ' fehler='.$errors."\n";

  exit($errors > 0 ? 1 : 0);
}
catch (Throwable $e)
{
  fwrite(STDERR, 'WebDAV-Derivate: '.get_class($e).': '.$e->getMessage()."\n");
  exit(1);
}
