<?php
define('PHPWG_ROOT_PATH', '../../');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  define('BRATONIEN_TOOLS_ID', basename(__DIR__));
  define('BRATONIEN_TOOLS_PATH', PHPWG_ROOT_PATH.'plugins/'.BRATONIEN_TOOLS_ID.'/');
}
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_image_runtime.inc.php');

function bratonien_tools_webdav_debug_out($status, array $data)
{
  http_response_code((int)$status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

global $user;
if (!isset($user['status']) || !in_array($user['status'], array('admin', 'webmaster'), true))
{
  bratonien_tools_webdav_debug_out(403, array('ok'=>false, 'stage'=>'auth', 'error'=>'Nur fuer Administratoren/Webmaster.'));
}

$image_id = (int)($_GET['id'] ?? 0);
if ($image_id < 1)
{
  bratonien_tools_webdav_debug_out(400, array('ok'=>false, 'stage'=>'input', 'error'=>'Bild-ID fehlt.'));
}

$debug = array(
  'ok'=>false,
  'image_id'=>$image_id,
  'stage'=>'start',
);

$result = pwg_query('SELECT id, path, coi FROM '.IMAGES_TABLE.' WHERE id='.$image_id.' LIMIT 1');
if (!pwg_db_num_rows($result))
{
  $debug['stage'] = 'image-row';
  $debug['error'] = 'Bild nicht gefunden.';
  bratonien_tools_webdav_debug_out(404, $debug);
}
$row = pwg_db_fetch_assoc($result);
$image_path = (string)($row['path'] ?? '');
$debug['images_path'] = $image_path;

$absolute = $image_path;
if (strpos($absolute, '/') !== 0)
{
  $absolute = PHPWG_ROOT_PATH.ltrim(preg_replace('#^\./#', '', $absolute), '/');
}
$debug['absolute_path'] = $absolute;
$debug['absolute_exists'] = file_exists($absolute);
$debug['absolute_is_file'] = is_file($absolute);
$debug['absolute_is_link'] = is_link($absolute);
if (is_link($absolute))
{
  $debug['link_target'] = readlink($absolute);
}

$resolved = realpath($absolute);
$debug['realpath'] = $resolved === false ? null : $resolved;
if ($resolved === false)
{
  $debug['stage'] = 'realpath';
  $debug['error'] = 'realpath() konnte images.path nicht aufloesen.';
  bratonien_tools_webdav_debug_out(200, $debug);
}

$normalized = str_replace('\\', '/', $resolved);
$debug['normalized_realpath'] = $normalized;

$match = array();
if (!preg_match('#/nc-webdav-source/connection-([0-9]+)/root-([0-9]+)/(.*)$#', $normalized, $match))
{
  $debug['stage'] = 'source-regex';
  $debug['error'] = 'realpath passt nicht zum erwarteten nc-webdav-source-Schema.';
  bratonien_tools_webdav_debug_out(200, $debug);
}

$connection_id = (int)$match[1];
$root_fileid = (int)$match[2];
$relative_path = trim((string)$match[3], '/');
$debug['connection_id'] = $connection_id;
$debug['root_fileid'] = $root_fileid;
$debug['relative_path'] = $relative_path;

$table = defined('BRATONIEN_TOOLS_NC_CONNECTIONS_TABLE')
  ? BRATONIEN_TOOLS_NC_CONNECTIONS_TABLE
  : $GLOBALS['prefixeTable'].'bratonien_tools_nc_connections';
$debug['connections_table'] = $table;

$connection_result = pwg_query('SELECT id, enabled, adapter, config_json FROM `'.$table.'` WHERE id='.$connection_id.' LIMIT 1');
if (!pwg_db_num_rows($connection_result))
{
  $debug['stage'] = 'connection-row';
  $debug['error'] = 'Erkannte WebDAV-Verbindung existiert nicht in der Connector-Tabelle.';
  bratonien_tools_webdav_debug_out(200, $debug);
}
$connection_row = pwg_db_fetch_assoc($connection_result);
$debug['connection_enabled'] = (int)($connection_row['enabled'] ?? 0);
$debug['connection_adapter'] = (string)($connection_row['adapter'] ?? '');

$config = json_decode((string)($connection_row['config_json'] ?? ''), true);
if (!is_array($config))
{
  $debug['stage'] = 'config-json';
  $debug['error'] = 'config_json ist kein gueltiges JSON-Objekt.';
  bratonien_tools_webdav_debug_out(200, $debug);
}

$debug['source_mode'] = (string)($config['source_mode'] ?? '');
$debug['state_dir'] = (string)($config['state_dir'] ?? '');
$debug['parallel_gallery_root'] = (string)($config['parallel_gallery_root'] ?? '');

$roots = isset($config['roots']) && is_array($config['roots']) ? $config['roots'] : array();
$debug['roots'] = array();
$root_path = '';
foreach ($roots as $root)
{
  $entry = array(
    'fileid'=>(int)($root['fileid'] ?? 0),
    'webdav_path'=>(string)($root['webdav_path'] ?? ''),
    'display_name'=>(string)($root['display_name'] ?? ''),
  );
  $debug['roots'][] = $entry;
  if ($entry['fileid'] === $root_fileid)
  {
    $root_path = trim($entry['webdav_path'], '/');
  }
}
$debug['matched_root_path'] = $root_path;
if ($debug['source_mode'] !== 'webdav-placeholder')
{
  $debug['stage'] = 'source-mode';
  $debug['error'] = 'source_mode ist nicht webdav-placeholder.';
  bratonien_tools_webdav_debug_out(200, $debug);
}
if ($root_path === '')
{
  $debug['stage'] = 'root-match';
  $debug['error'] = 'root_fileid aus dem Dateipfad kommt in config.roots nicht vor.';
  bratonien_tools_webdav_debug_out(200, $debug);
}

$mapping_file = rtrim((string)($config['state_dir'] ?? ''), '/').'/webdav-map.json';
$debug['mapping_file'] = $mapping_file;
$debug['mapping_readable'] = is_readable($mapping_file);
$debug['mapping_has_resolved_key'] = false;
$debug['mapping_has_normalized_key'] = false;
$debug['mapping_entry'] = null;

if (is_readable($mapping_file))
{
  $mapping = json_decode((string)file_get_contents($mapping_file), true);
  if (is_array($mapping) && isset($mapping['files']) && is_array($mapping['files']))
  {
    $debug['mapping_has_resolved_key'] = array_key_exists($resolved, $mapping['files']);
    $debug['mapping_has_normalized_key'] = array_key_exists($normalized, $mapping['files']);
    $entry = $mapping['files'][$resolved] ?? $mapping['files'][$normalized] ?? null;
    if (is_array($entry))
    {
      $debug['mapping_entry'] = $entry;
    }
  }
  else
  {
    $debug['mapping_error'] = 'Mapping-Datei ist ungueltig oder enthaelt kein files-Objekt.';
  }
}

$debug['runtime_source_info'] = bratonien_tools_webdav_image_source_info($image_id);
$debug['stage'] = $debug['runtime_source_info'] ? 'ok' : 'runtime-source-info-null';
$debug['ok'] = (bool)$debug['runtime_source_info'];
if (!$debug['ok'])
{
  $debug['error'] = 'Die produktive Zuordnungsfunktion liefert trotz der obigen Zwischenergebnisse null.';
}

bratonien_tools_webdav_debug_out(200, $debug);
