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
$mapped_files = 0;
foreach ($mapping['files'] as $path => $entry)
{
  if (!is_array($entry) || (string)($entry['kind'] ?? '') !== 'file') continue;
  $mapped_files++;
  $width = (int)($entry['width'] ?? 0);
  $height = (int)($entry['height'] ?? 0);
  if ($width < 1 || $height < 1) continue;
  $dimensions[str_replace('\\', '/', (string)$path)] = array($width, $height);
}

if ($mapped_files === 0)
{
  echo "WebDAV-Metadaten: keine aktuellen Bildquellen vorhanden.\n";
  exit(0);
}

// Nextcloud liefert die Bildabmessungen ueber WebDAV nicht fuer jede Datei.
// Das ist kein Fehler des Connector-Syncs: die Dateien und ihr Shadow Tree
// sind trotzdem gueltig und Piwigo kann die Bildquelle spaeter bei Bedarf
// materialisieren. Vorhandene Abmessungen werden uebernommen, fehlende Werte
// bleiben unangetastet. Nur ein syntaktisch/technisch ungueltiges Mapping ist
// oben weiterhin fatal.
if (!$dimensions)
{
  echo 'WebDAV-Metadaten: bilder='.$mapped_files.' aktualisiert=0 ohne_masse='.$mapped_files." (Nextcloud lieferte keine Bildabmessungen; Synchronisierung bleibt gueltig)\n";
  exit(0);
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
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Metadata/0.9.7.1.47';
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

echo 'WebDAV-Metadaten: bilder='.$checked.' aktualisiert='.$updated.' ohne_masse='.$missing;
if ($missing > 0)
{
  echo ' (fehlende WebDAV-Abmessungen sind nicht fatal)';
}
echo "\n";
exit(0);
