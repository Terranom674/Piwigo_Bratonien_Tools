#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$options = getopt('', array('piwigo-root:', 'connection-id:', 'mapping:'));
$piwigo_root = rtrim((string)($options['piwigo-root'] ?? ''), '/');
$connection_id = (int)($options['connection-id'] ?? 0);
$mapping_file = (string)($options['mapping'] ?? '');

if ($piwigo_root === '' || $connection_id < 1 || $mapping_file === '' || !is_readable($mapping_file))
{
  fwrite(STDERR, "Parameter --piwigo-root, --connection-id und --mapping werden benoetigt.\n");
  exit(1);
}

$mapping = json_decode((string)file_get_contents($mapping_file), true);
if (!is_array($mapping) || !isset($mapping['files']) || !is_array($mapping['files']))
{
  fwrite(STDERR, "WebDAV-Mapping ist ungueltig.\n");
  exit(1);
}

$dimensions = array();
foreach ($mapping['files'] as $path => $entry)
{
  if (!is_array($entry) || (string)($entry['kind'] ?? '') !== 'file') continue;
  $width = (int)($entry['width'] ?? 0);
  $height = (int)($entry['height'] ?? 0);
  if ($width < 1 || $height < 1) continue;
  $dimensions[str_replace('\\', '/', (string)$path)] = array($width, $height);
}

if (!$dimensions)
{
  fwrite(STDERR, "WebDAV-Mapping enthaelt keine Originalabmessungen.\n");
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
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/lib/sync-webdav-metadata.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Metadata/0.9.6.8.20';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');

$checked = 0;
$updated = 0;
$missing = 0;
$result = pwg_query('SELECT id, path, width, height FROM '.IMAGES_TABLE.' ORDER BY id');
while ($row = pwg_db_fetch_assoc($result))
{
  $path = (string)($row['path'] ?? '');
  if ($path === '') continue;

  $absolute = $path;
  if (strpos($absolute, '/') !== 0)
  {
    $absolute = PHPWG_ROOT_PATH.ltrim(preg_replace('#^\./#', '', $absolute), '/');
  }

  $normalized_shadow = str_replace('\\', '/', $absolute);
  if (strpos($normalized_shadow, '/nc-webdav-gallery/connection-'.$connection_id.'/') === false) continue;

  $checked++;
  $resolved = realpath($absolute);
  if ($resolved === false)
  {
    $missing++;
    continue;
  }

  $key = str_replace('\\', '/', $resolved);
  if (!isset($dimensions[$key]))
  {
    $missing++;
    continue;
  }

  list($width, $height) = $dimensions[$key];
  if ((int)$row['width'] === $width && (int)$row['height'] === $height) continue;

  pwg_query('UPDATE '.IMAGES_TABLE.' SET width='.$width.', height='.$height.' WHERE id='.(int)$row['id']);
  $updated++;
}

if ($updated > 0)
{
  if (function_exists('update_category')) update_category('all');
  if (function_exists('invalidate_user_cache')) invalidate_user_cache(true);
}

echo 'WebDAV-Metadaten: bilder='.$checked.' aktualisiert='.$updated.' ohne_masse='.$missing."\n";
exit($missing > 0 ? 1 : 0);
