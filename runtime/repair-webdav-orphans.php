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

$stateRoot = '/var/lib/bratonien-tools/nc-connector';
$marker = $stateRoot.'/.webdav-orphan-repair-0963.done';

try
{
  if (is_file($marker))
  {
    echo "NC WebDAV Altlasten-Reparatur: bereits abgeschlossen.\n";
    exit(0);
  }

  if (!is_dir($stateRoot) && !mkdir($stateRoot, 0750, true))
  {
    throw new RuntimeException('State-Verzeichnis konnte nicht angelegt werden: '.$stateRoot);
  }

  $relativePrefix = './_data/bratonien-tools/nc-webdav-gallery/connection-';
  $absolutePrefix = rtrim(PHPWG_ROOT_PATH, '/').'/_data/bratonien-tools/nc-webdav-gallery/connection-';
  $relativeLength = strlen($relativePrefix);
  $absoluteLength = strlen($absolutePrefix);

  $query = "SELECT id,path FROM ".IMAGES_TABLE.
    " WHERE LEFT(path,".$relativeLength.")='".pwg_db_real_escape_string($relativePrefix)."'".
    " OR LEFT(path,".$absoluteLength.")='".pwg_db_real_escape_string($absolutePrefix)."'";
  $result = pwg_query($query);

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

    if (!is_file($filesystemPath))
    {
      $staleIds[] = $id;
    }
  }

  $staleIds = array_values(array_unique(array_map('intval', $staleIds)));
  if ($staleIds)
  {
    delete_elements($staleIds, false);
    invalidate_user_cache(true);
  }

  $payload = date('c').' removed='.count($staleIds)."\n";
  if (file_put_contents($marker, $payload, LOCK_EX) === false)
  {
    throw new RuntimeException('Abschlussmarker konnte nicht geschrieben werden: '.$marker);
  }
  @chmod($marker, 0640);

  echo 'NC WebDAV Altlasten-Reparatur: entfernte verwaiste Bilder='.count($staleIds)."\n";
  exit(0);
}
catch (Throwable $e)
{
  fwrite(STDERR, 'NC WebDAV Altlasten-Reparatur: '.$e->getMessage()."\n");
  exit(1);
}
