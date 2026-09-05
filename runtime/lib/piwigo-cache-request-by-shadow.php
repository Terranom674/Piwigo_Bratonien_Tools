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
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/lib/piwigo-cache-request-by-shadow.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-Piwigo-Shadow-Cache-Bridge/0.9.7.1.41';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');
require_once(PHPWG_ROOT_PATH.'admin/include/image.class.php');
if (!defined('BRATONIEN_TOOLS_PATH'))
{
  fwrite(STDERR, "Bratonien Tools ist nicht aktiv.\n");
  exit(1);
}
require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_cache_validation.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_materialize_runtime.inc.php');

function bratonien_tools_shadow_bridge_fail($message, $code=1)
{
  fwrite(STDERR, $message."\n");
  exit((int)$code);
}

function bratonien_tools_shadow_bridge_i_request($target)
{
  $root = PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR;
  $target = str_replace('\\', '/', (string)$target);
  $root = str_replace('\\', '/', (string)$root);
  if (strpos($target, $root) !== 0) return null;
  $relative = ltrim(substr($target, strlen($root)), '/');
  if ($relative === '') return null;
  return '/i.php?/'.implode('/', array_map('rawurlencode', explode('/', $relative)));
}

function bratonien_tools_shadow_bridge_call_i($request, &$detail=null)
{
  $detail = '';
  $runner = BRATONIEN_TOOLS_PATH.'runtime/lib/piwigo-derivative-call.php';
  if (!is_file($runner)) { $detail = 'Piwigo-Derivataufruf fehlt.'; return false; }
  if (!function_exists('exec')) { $detail = 'PHP exec() ist deaktiviert.'; return false; }
  $output = array();
  $exit = 1;
  @exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($runner).' --request='.escapeshellarg($request).' 2>&1', $output, $exit);
  if ($exit !== 0)
  {
    $detail = 'Piwigo-Aufruf Exit '.$exit;
    if ($output) $detail .= ': '.implode(' ', array_slice($output, -3));
    return false;
  }
  return true;
}

function bratonien_tools_shadow_bridge_sync_actual_metadata(array &$row, $source_path)
{
  $size = @getimagesize($source_path);
  if (!is_array($size) || (int)($size[0] ?? 0) < 1 || (int)($size[1] ?? 0) < 1)
  {
    bratonien_tools_shadow_bridge_fail('Das temporär eingesetzte Original besitzt keine lesbaren Bildabmessungen.');
  }

  $width = (int)$size[0];
  $height = (int)$size[1];
  $rotation_angle = pwg_image::get_rotation_angle($source_path);
  $rotation_angle = $rotation_angle === null ? 0 : (int)$rotation_angle;
  $rotation = pwg_image::get_rotation_code_from_angle($rotation_angle);
  if ($rotation === null) $rotation = 0;
  $rotation = (int)$rotation;

  if (
    (int)($row['width'] ?? 0) === $width
    && (int)($row['height'] ?? 0) === $height
    && isset($row['rotation'])
    && (int)$row['rotation'] === $rotation
  )
  {
    return false;
  }

  pwg_query(
    'UPDATE '.IMAGES_TABLE
    .' SET width='.$width.', height='.$height.', rotation='.$rotation
    .' WHERE id='.(int)$row['id'].' LIMIT 1'
  );
  $row['width'] = $width;
  $row['height'] = $height;
  $row['rotation'] = $rotation;
  return true;
}

function bratonien_tools_shadow_bridge_variants(array $row, $stage)
{
  $src = new SrcImage($row);
  $variants = array();

  foreach (ImageStdParams::get_defined_type_map() as $type=>$params)
  {
    $derivative = new DerivativeImage($params, $src);
    if ($derivative->same_as_source()) continue;
    $size = $derivative->get_size();
    $max_edge = max((int)($size[0] ?? 0), (int)($size[1] ?? 0));
    $priority = $max_edge > 0 && $max_edge <= 1920;
    if (($stage === 1 && $priority) || ($stage === 2 && !$priority))
    {
      $variants[] = array('name'=>'standard:'.$type, 'derivative'=>$derivative, 'params'=>$params, 'target'=>$derivative->get_path());
    }
  }

  foreach (ImageStdParams::$custom as $key=>$last_used)
  {
    $params = bratonien_tools_webdav_materialize_custom_params($key);
    if (!$params) continue;
    $derivative = new DerivativeImage($params, $src);
    if ($derivative->same_as_source()) continue;
    $size = $derivative->get_size();
    $max_edge = max((int)($size[0] ?? 0), (int)($size[1] ?? 0));
    $priority = $max_edge > 0 && $max_edge <= 1920;
    if (($stage === 1 && $priority) || ($stage === 2 && !$priority))
    {
      $variants[] = array('name'=>'custom:'.$key, 'derivative'=>$derivative, 'params'=>$params, 'target'=>$derivative->get_path());
    }
  }

  return $variants;
}

$connection_id = 0;
$shadow_relative = '';
$stage = 0;
foreach ($argv as $arg)
{
  if (preg_match('/^--connection-id=(\d+)$/', $arg, $m)) $connection_id = (int)$m[1];
  elseif (strpos($arg, '--shadow-relative=') === 0) $shadow_relative = ltrim(substr($arg, strlen('--shadow-relative=')), '/');
  elseif (preg_match('/^--stage=([12])$/', $arg, $m)) $stage = (int)$m[1];
}
if ($connection_id < 1 || $shadow_relative === '' || !in_array($stage, array(1,2), true))
{
  bratonien_tools_shadow_bridge_fail('Parameter --connection-id, --shadow-relative und --stage=1|2 werden benötigt.', 2);
}
if (strpos($shadow_relative, '..') !== false || strpos($shadow_relative, "\0") !== false)
{
  bratonien_tools_shadow_bridge_fail('Unsicherer Shadow-Pfad.', 2);
}

$connection = bratonien_tools_nc_connector_connection($connection_id, false);
if (!$connection || empty($connection['enabled'])) bratonien_tools_shadow_bridge_fail('WebDAV-Verbindung wurde nicht gefunden.');
$config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
$gallery_root = rtrim((string)($config['parallel_gallery_root'] ?? ''), '/');
if ($gallery_root === '' || !is_dir($gallery_root)) bratonien_tools_shadow_bridge_fail('Shadow-Tree der Verbindung fehlt.');

$shadow_absolute = $gallery_root.'/'.$shadow_relative;
if (!is_link($shadow_absolute)) bratonien_tools_shadow_bridge_fail('Shadow-Datei ist kein Symlink: '.$shadow_relative);
$shadow_real = realpath($shadow_absolute);
if ($shadow_real === false || !is_file($shadow_real)) bratonien_tools_shadow_bridge_fail('Shadow-Ziel ist nicht lesbar: '.$shadow_relative);

$root = rtrim(str_replace('\\', '/', PHPWG_ROOT_PATH), '/');
$gallery = rtrim(str_replace('\\', '/', $gallery_root), '/');
if (strpos($gallery.'/', $root.'/') !== 0) bratonien_tools_shadow_bridge_fail('Shadow-Tree liegt außerhalb von Piwigo.');
$relative_gallery = ltrim(substr($gallery, strlen($root)), '/');
$db_path = './'.$relative_gallery.'/'.$shadow_relative;

$result = pwg_query("SELECT * FROM ".IMAGES_TABLE." WHERE path='".pwg_db_real_escape_string($db_path)."' LIMIT 2");
$rows = array();
while ($row = pwg_db_fetch_assoc($result)) $rows[] = $row;
if (count($rows) !== 1)
{
  bratonien_tools_shadow_bridge_fail('Piwigo kann den Shadow-Pfad nicht eindeutig einem Bild zuordnen: '.$db_path);
}
$row = $rows[0];
$metadata_repaired = bratonien_tools_shadow_bridge_sync_actual_metadata($row, $shadow_real);

$variants = bratonien_tools_shadow_bridge_variants($row, $stage);
$generated = 0;
$already = 0;
$invalid_removed = 0;
foreach ($variants as $variant)
{
  $reason = '';
  if (bratonien_tools_webdav_derivative_cache_valid($variant['target'], $variant['derivative'], $variant['params'], $reason))
  {
    $already++;
    continue;
  }

  // Ein vorhandenes, aber geometrisch falsches Derivat darf nicht an i.php
  // vorbeikommen. Piwigo entscheidet sonst allein anhand von mtimes und kann
  // eine alte Datei trotz korrigierter Breite/Höhe/EXIF-Rotation behalten.
  if (is_file($variant['target']))
  {
    if (!@unlink($variant['target']))
    {
      bratonien_tools_shadow_bridge_fail($variant['name'].': ungültiges Alt-Derivat konnte nicht entfernt werden ('.$reason.').');
    }
    $invalid_removed++;
  }

  $request = bratonien_tools_shadow_bridge_i_request($variant['target']);
  if ($request === null) bratonien_tools_shadow_bridge_fail($variant['name'].': ungültiger Piwigo-Derivatpfad.');

  $detail = '';
  if (!bratonien_tools_shadow_bridge_call_i($request, $detail))
  {
    bratonien_tools_shadow_bridge_fail($variant['name'].': '.$detail);
  }
  clearstatcache(true, $variant['target']);
  $reason = '';
  if (!bratonien_tools_webdav_derivative_cache_valid($variant['target'], $variant['derivative'], $variant['params'], $reason))
  {
    bratonien_tools_shadow_bridge_fail($variant['name'].': Piwigo hat kein gültiges Derivat erzeugt ('.$reason.').');
  }
  $generated++;
}

echo json_encode(array(
  'ok'=>true,
  'connection_id'=>$connection_id,
  'shadow_relative'=>$shadow_relative,
  'stage'=>$stage,
  'piwigo_image_id'=>(int)$row['id'],
  'metadata_repaired'=>$metadata_repaired,
  'variants'=>count($variants),
  'generated'=>$generated,
  'already_valid'=>$already,
  'invalid_removed'=>$invalid_removed,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
exit(0);
