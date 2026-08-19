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

$db_config = $piwigo_root.'/local/config/database.inc.php';
if (!is_readable($db_config))
{
  fwrite(STDERR, "Piwigo-Datenbankkonfiguration ist nicht lesbar.\n");
  exit(1);
}

$conf = array();
$prefixeTable = 'piwigo_';
require $db_config;
foreach (array('db_host','db_user','db_password','db_base') as $key)
{
  if (!isset($conf[$key]))
  {
    fwrite(STDERR, "Piwigo-Datenbankkonfiguration ist unvollstaendig: $key\n");
    exit(1);
  }
}

$db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
if ($db->connect_errno)
{
  fwrite(STDERR, "Piwigo-Datenbank ist nicht erreichbar: ".$db->connect_error."\n");
  exit(1);
}
$db->set_charset('utf8mb4');

$table = $prefixeTable.'bratonien_tools_nc_connections';
$result = $db->query('SELECT config_json FROM `'.$table.'` WHERE id='.$connection_id.' LIMIT 1');
if (!$result || !$result->num_rows)
{
  fwrite(STDERR, "Connector-Verbindung #$connection_id wurde nicht gefunden.\n");
  exit(1);
}
$config = json_decode((string)$result->fetch_assoc()['config_json'], true);
if (!is_array($config) || (string)($config['source_mode'] ?? '') !== 'webdav-placeholder')
{
  echo "Keine WebDAV-Bereinigung erforderlich.\n";
  exit(0);
}

$prefixes = array(
  './_data/bratonien-tools/nc-webdav-gallery/connection-'.$connection_id.'/',
  './_data/bratonien-tools/nc-webdav-source/connection-'.$connection_id.'/',
  './galleries/bratonien-webdav-'.$connection_id.'/',
);

$gallery_root = rtrim(str_replace('\\', '/', (string)($config['parallel_gallery_root'] ?? '')), '/');
$normalized_piwigo_root = rtrim(str_replace('\\', '/', $piwigo_root), '/');
if ($gallery_root !== '' && strpos($gallery_root, $normalized_piwigo_root.'/') === 0)
{
  $relative = ltrim(substr($gallery_root, strlen($normalized_piwigo_root)), '/');
  if ($relative !== '') $prefixes[] = './'.rtrim($relative, '/').'/';
}
$prefixes = array_values(array_unique($prefixes));

$where = array();
foreach ($prefixes as $prefix)
{
  $escaped = $db->real_escape_string(addcslashes($prefix, "_%\\"));
  $where[] = "path LIKE '{$escaped}%' ESCAPE '\\\\'";
}

$images_table = $prefixeTable.'images';
$rows = $db->query("SELECT id, path FROM `{$images_table}` WHERE ".implode(' OR ', $where));
if (!$rows)
{
  fwrite(STDERR, "Connector-Bilder konnten nicht gelesen werden: ".$db->error."\n");
  exit(1);
}

$missing_ids = array();
while ($row = $rows->fetch_assoc())
{
  $path = (string)$row['path'];
  $owned = false;
  foreach ($prefixes as $prefix)
  {
    if (strpos($path, $prefix) === 0)
    {
      $owned = true;
      break;
    }
  }
  if (!$owned) continue;

  $absolute = $piwigo_root.'/'.ltrim(preg_replace('#^\./#', '', $path), '/');
  if (!is_file($absolute))
  {
    $missing_ids[] = (int)$row['id'];
  }
}
$db->close();

if (!$missing_ids)
{
  echo "WebDAV-Bereinigung: keine fehlenden Piwigo-Bilder.\n";
  exit(0);
}

// Dieser Prozess wird ausschliesslich nach einem erfolgreich abgeschlossenen
// WebDAV-Aufbau und erfolgreicher Piwigo-Synchronisierung gestartet. Eine
// nicht erreichbare Verbindung kann deshalb niemals ueber diesen Pfad Bilder
// entfernen.
define('PHPWG_ROOT_PATH', $piwigo_root.'/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/cleanup-missing-webdav-images.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

$deleted = delete_elements(array_values(array_unique(array_map('intval', $missing_ids))), false);
update_category('all');
invalidate_user_cache(true);

echo 'WebDAV-Bereinigung: '.(int)$deleted." nicht mehr freigegebene/vorhandene Bilder aus Piwigo entfernt.\n";
