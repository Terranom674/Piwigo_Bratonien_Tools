#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$connection_id = 0;
foreach ($argv as $arg)
{
  if (preg_match('/^--connection-id=(\d+)$/', $arg, $m)) $connection_id = (int)$m[1];
}
if ($connection_id < 1)
{
  fwrite(STDERR, "--connection-id fehlt oder ist ungueltig.\n");
  exit(2);
}

$piwigo_root = realpath(dirname(__DIR__, 4));
if ($piwigo_root === false)
{
  fwrite(STDERR, "Piwigo-Root wurde nicht gefunden.\n");
  exit(3);
}
define('PHPWG_ROOT_PATH', rtrim($piwigo_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/lib/webdav-scan-diagnostic.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Scan-Diagnostic/0.9.7.1.20';
$_SERVER['HTTPS'] = 'off';

try
{
  require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
  if (!defined('BRATONIEN_TOOLS_PATH')) throw new RuntimeException('Bratonien Tools ist nicht aktiv.');
  require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector.inc.php');

  $connection = bratonien_tools_nc_connector_connection($connection_id, true);
  if (!$connection || empty($connection['enabled'])) throw new RuntimeException('WebDAV-Verbindung wurde nicht gefunden oder ist deaktiviert.');
  if (!bratonien_tools_nc_connector_is_webdav($connection)) throw new RuntimeException('Verbindung ist keine WebDAV-Placeholder-Verbindung.');

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
  $gallery_root = rtrim((string)($config['parallel_gallery_root'] ?? ''), '/');
  if ($state_dir === '') throw new RuntimeException('Connector-State-Verzeichnis fehlt.');
  if ($gallery_root === '') throw new RuntimeException('Shadow-Tree-Pfad fehlt in der Connector-Konfiguration.');

  $mapping_file = $state_dir.'/webdav-map.json';
  if (!is_file($mapping_file) || !is_readable($mapping_file)) throw new RuntimeException('WebDAV-Mapping fehlt oder ist nicht lesbar: '.$mapping_file);
  $mapping = json_decode((string)file_get_contents($mapping_file), true);
  if (!is_array($mapping) || !isset($mapping['files']) || !is_array($mapping['files'])) throw new RuntimeException('WebDAV-Mapping ist ungueltig.');
  if (!is_dir($gallery_root)) throw new RuntimeException('Shadow-Tree fehlt: '.$gallery_root);

  $normalize = static function ($path) {
    return rtrim(str_replace('\\', '/', (string)$path), '/');
  };

  $mapped_files = array();
  foreach ($mapping['files'] as $path=>$meta)
  {
    if (!is_array($meta) || (string)($meta['kind'] ?? '') !== 'file') continue;
    $mapped_files[$normalize($path)] = $meta;
  }

  $shadow_links = 0;
  $matched = 0;
  $broken = 0;
  $unmapped = 0;
  $duplicates = 0;
  $seen = array();
  $examples = array();

  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($gallery_root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
  );
  foreach ($iterator as $item)
  {
    $shadow = $item->getPathname();
    if (!is_link($shadow)) continue;
    $shadow_links++;
    $resolved = realpath($shadow);
    if ($resolved === false || !is_file($resolved))
    {
      $broken++;
      if (count($examples) < 8) $examples[] = array('state'=>'broken','shadow'=>$shadow);
      continue;
    }
    $resolved = $normalize($resolved);
    if (!isset($mapped_files[$resolved]))
    {
      $unmapped++;
      if (count($examples) < 8) $examples[] = array('state'=>'unmapped','shadow'=>$shadow,'target'=>$resolved);
      continue;
    }
    if (isset($seen[$resolved]))
    {
      $duplicates++;
      if (count($examples) < 8) $examples[] = array('state'=>'duplicate','shadow'=>$shadow,'target'=>$resolved);
      continue;
    }
    $seen[$resolved] = true;
    $matched++;
    if (count($examples) < 8)
    {
      $meta = $mapped_files[$resolved];
      $examples[] = array(
        'state'=>'matched',
        'shadow'=>$shadow,
        'target'=>$resolved,
        'fileid'=>(int)($meta['fileid'] ?? 0),
        'webdav_path'=>(string)($meta['webdav_path'] ?? ''),
        'etag'=>(string)($meta['etag'] ?? ''),
      );
    }
  }

  $payload = array(
    'ok'=>($shadow_links > 0 && $matched === $shadow_links && $broken === 0 && $unmapped === 0 && $duplicates === 0),
    'connection_id'=>$connection_id,
    'state_dir'=>$state_dir,
    'gallery_root'=>$gallery_root,
    'mapping_file'=>$mapping_file,
    'mapping_files'=>count($mapped_files),
    'shadow_links'=>$shadow_links,
    'matched'=>$matched,
    'broken'=>$broken,
    'unmapped'=>$unmapped,
    'duplicates'=>$duplicates,
    'examples'=>$examples,
  );
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
  exit($payload['ok'] ? 0 : 4);
}
catch (Throwable $e)
{
  echo json_encode(array(
    'ok'=>false,
    'connection_id'=>$connection_id,
    'fatal'=>$e->getMessage(),
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
  exit(1);
}
