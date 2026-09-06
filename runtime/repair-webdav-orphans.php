#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$pluginRoot = dirname(__DIR__);
$piwigoRoot = dirname($pluginRoot, 2);
define('PHPWG_ROOT_PATH', rtrim($piwigoRoot, '/').'/');

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/repair-webdav-orphans.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

try
{
  $relativePrefix = './_data/bratonien-tools/nc-webdav-gallery/connection-';
  $absolutePrefix = rtrim(PHPWG_ROOT_PATH, '/').'/_data/bratonien-tools/nc-webdav-gallery/connection-';
  $relativeLength = strlen($relativePrefix);
  $absoluteLength = strlen($absolutePrefix);

  $query = "SELECT id,path FROM ".IMAGES_TABLE.
    " WHERE LEFT(path,".$relativeLength.")='".pwg_db_real_escape_string($relativePrefix)."'".
    " OR LEFT(path,".$absoluteLength.")='".pwg_db_real_escape_string($absolutePrefix)."'";
  $result = pwg_query($query);

  $checked = 0;
  $staleIds = array();
  while ($row = pwg_db_fetch_assoc($result))
  {
    $id = (int)$row['id'];
    $storedPath = (string)$row['path'];
    if ($id < 1 || $storedPath === '') continue;

    if (strpos($storedPath, $relativePrefix) === 0)
    {
      $filesystemPath = rtrim(PHPWG_ROOT_PATH, '/').'/'.ltrim(substr($storedPath, 2), '/');
    }
    elseif (strpos($storedPath, $absolutePrefix) === 0)
    {
      $filesystemPath = $storedPath;
    }
    else
    {
      continue;
    }

    $checked++;
    if (!is_file($filesystemPath))
    {
      $staleIds[] = $id;
    }
  }

  $staleIds = array_values(array_unique(array_map('intval', $staleIds)));
  if ($staleIds)
  {
    // Die Shadow-Quelle existiert nicht mehr. Der Bilddatensatz ist damit
    // unabhängig von eventuellen virtuellen Album-Verknüpfungen ungültig.
    // delete_elements(..., false) entfernt Piwigos Datensatz und Derivate,
    // fasst aber keine bereits verschwundene Quelldatei an.
    delete_elements($staleIds, false);
    invalidate_user_cache(true);
  }

  echo 'NC WebDAV laufende Bereinigung: geprueft='.$checked.' entfernte_verwaiste_bilder='.count($staleIds)."\n";
  exit(0);
}
catch (Throwable $e)
{
  fwrite(STDERR, 'NC WebDAV laufende Bereinigung: '.$e->getMessage()."\n");
  exit(1);
}
