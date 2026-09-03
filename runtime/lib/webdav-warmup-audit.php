<?php
if (PHP_SAPI !== 'cli')
{
  http_response_code(404);
  exit;
}

$piwigo_root = realpath(dirname(__DIR__, 4));
if ($piwigo_root === false)
{
  fwrite(STDERR, "Piwigo-Root wurde nicht gefunden.\n");
  exit(1);
}
define('PHPWG_ROOT_PATH', rtrim($piwigo_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/lib/webdav-warmup-audit.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Warmup-Audit/0.9.7.1.8';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
if (!defined('BRATONIEN_TOOLS_PATH'))
{
  fwrite(STDERR, "Bratonien Tools ist nicht aktiv.\n");
  exit(1);
}
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_materialize_runtime.inc.php');

$total = 0;
$connector = 0;
$errors = array();
$warnings = array();
$resolved_to_ids = array();

$result = pwg_query('SELECT id, path FROM '.IMAGES_TABLE.' ORDER BY id');
while ($row = pwg_db_fetch_assoc($result))
{
  $total++;
  $image_id = (int)$row['id'];
  $info = bratonien_tools_webdav_materialize_source_info($image_id);
  if (!$info) continue;
  $connector++;

  $logical = (string)$row['path'];
  if ($logical === '')
  {
    $errors[] = 'Bild #'.$image_id.': Piwigo-Pfad ist leer.';
    continue;
  }
  if (strpos($logical, '/') !== 0)
  {
    $logical = PHPWG_ROOT_PATH.ltrim(preg_replace('#^\./#', '', $logical), '/');
  }

  $resolved = realpath($logical);
  if ($resolved === false || !is_file($resolved))
  {
    $errors[] = 'Bild #'.$image_id.': Piwigo-Pfad kann nicht auf eine Datei aufgelöst werden: '.$logical;
    continue;
  }

  $normalized = str_replace('\\', '/', $resolved);
  $expected = '/nc-webdav-source/connection-'.(int)$info['connection_id'].'/root-'.(int)$info['root_fileid'].'/';
  if (strpos($normalized, $expected) === false)
  {
    $errors[] = 'Bild #'.$image_id.': realpath liegt nicht in der erwarteten Connector-Wurzel: '.$normalized;
  }

  $webdav_path = trim((string)($info['webdav_path'] ?? ''), '/');
  if ($webdav_path === '')
  {
    $errors[] = 'Bild #'.$image_id.': WebDAV-Pfad fehlt.';
  }
  if ((int)($info['connection_id'] ?? 0) < 1 || (int)($info['root_fileid'] ?? 0) < 1)
  {
    $errors[] = 'Bild #'.$image_id.': Verbindung oder Root-fileid ist ungültig.';
  }
  if ((int)($info['fileid'] ?? 0) < 1)
  {
    $warnings[] = 'Bild #'.$image_id.': Nextcloud-fileid fehlt; Original-Fallback bleibt möglich.';
  }

  if (!isset($resolved_to_ids[$normalized])) $resolved_to_ids[$normalized] = array();
  $resolved_to_ids[$normalized][] = $image_id;
}

foreach ($resolved_to_ids as $path => $ids)
{
  if (count($ids) > 1)
  {
    $warnings[] = 'Mehrere Piwigo-Bild-IDs zeigen auf dieselbe physische Connector-Quelle: '.implode(',', $ids).' -> '.$path;
  }
}

printf("WebDAV-Warmup-Pfadaudit\n");
printf("Piwigo-Bilder gesamt: %d\n", $total);
printf("Connector-Bilder: %d\n", $connector);
printf("Fehler: %d\n", count($errors));
printf("Warnungen: %d\n", count($warnings));

foreach ($errors as $error) fwrite(STDERR, "ERROR: ".$error."\n");
foreach ($warnings as $warning) fwrite(STDOUT, "WARN: ".$warning."\n");

exit($errors ? 2 : 0);
