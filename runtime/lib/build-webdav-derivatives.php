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
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Derivative-Builder/0.9.5.20';
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
  $variants = bratonien_tools_webdav_derivative_variants();
  if (!$variants)
  {
    throw new RuntimeException('Keine Piwigo-Derivate konfiguriert.');
  }

  $images = 0;
  $generated = 0;
  $errors = 0;

  $result = pwg_query('SELECT * FROM '.IMAGES_TABLE.' ORDER BY id');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $image_id = (int)$row['id'];
    $info = bratonien_tools_webdav_image_source_info($image_id);
    if (!$info || (int)$info['connection_id'] !== $connection_id) continue;

    $images++;
    $src = new SrcImage($row);
    foreach ($variants as $variant_name => $params)
    {
      try
      {
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

  echo 'WebDAV-Derivate: bilder='.$images.' varianten='.count($variants).' bereit='.$generated.' fehler='.$errors."\n";
  exit($errors > 0 ? 1 : 0);
}
catch (Throwable $e)
{
  fwrite(STDERR, 'WebDAV-Derivate: '.$e->getMessage()."\n");
  exit(1);
}
