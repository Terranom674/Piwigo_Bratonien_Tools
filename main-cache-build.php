<?php
if (PHP_SAPI !== 'cli')
{
  http_response_code(404);
  exit;
}

$piwigo_root = realpath(dirname(__DIR__, 2));
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
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/main-cache-build.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-Piwigo-Cache-Builder/1.0';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
if (!defined('BRATONIEN_TOOLS_PATH'))
{
  fwrite(STDERR, "Bratonien Tools ist nicht aktiv.\n");
  exit(1);
}

require_once(BRATONIEN_TOOLS_PATH.'tools/image_cache.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/watermark_engine.inc.php');
require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');
require_once(PHPWG_ROOT_PATH.'admin/include/image.class.php');

function bratonien_tools_cache_builder_status($state, $message, $total, $completed, $generated, $cached, $skipped, $errors, $current='')
{
  bratonien_tools_write_main_cache_status(array(
    'state'=>$state,
    'message'=>$message,
    'total'=>(int)$total,
    'completed'=>(int)$completed,
    'generated'=>(int)$generated,
    'cached'=>(int)$cached,
    'skipped'=>(int)$skipped,
    'errors'=>(int)$errors,
    'current'=>(string)$current,
  ));
}

function bratonien_tools_cache_builder_size($value)
{
  $parts = explode('x', (string)$value, 2);
  if (count($parts) === 1)
  {
    $size = max(1, (int)$parts[0]);
    return array($size, $size);
  }
  return array(max(1, (int)$parts[0]), max(1, (int)$parts[1]));
}

function bratonien_tools_cache_builder_custom_params($key)
{
  $tokens = explode('_', (string)$key);
  if (!$tokens)
  {
    return null;
  }

  $token = array_shift($tokens);
  $crop = 0;
  $min_size = null;
  if (isset($token[0]) && $token[0] === 's')
  {
    $size = bratonien_tools_cache_builder_size(substr($token, 1));
  }
  elseif (isset($token[0]) && $token[0] === 'e')
  {
    $crop = 1;
    $size = $min_size = bratonien_tools_cache_builder_size(substr($token, 1));
  }
  else
  {
    if (count($tokens) < 2)
    {
      return null;
    }
    $size = bratonien_tools_cache_builder_size($token);
    $crop_token = array_shift($tokens);
    $min_size = bratonien_tools_cache_builder_size(array_shift($tokens));
    $crop = char_to_fraction($crop_token);
  }

  $params = new DerivativeParams(new SizingParams($size, $crop, $min_size));
  ImageStdParams::apply_global($params);
  return $params;
}

function bratonien_tools_cache_builder_effective_params_from_path($path)
{
  $filename = pathinfo($path, PATHINFO_FILENAME);
  $dash = strrpos($filename, '-');
  if ($dash === false)
  {
    return null;
  }

  $tokens = explode('_', substr($filename, $dash + 1));
  $type_token = array_shift($tokens);
  foreach (ImageStdParams::get_defined_type_map() as $type => $params)
  {
    if (function_exists('derivative_to_url') && derivative_to_url($type) === $type_token)
    {
      return $params;
    }
  }

  if (defined('IMG_CUSTOM') && function_exists('derivative_to_url') && derivative_to_url(IMG_CUSTOM) === $type_token)
  {
    return bratonien_tools_cache_builder_custom_params(implode('_', $tokens));
  }
  return null;
}

function bratonien_tools_cache_builder_metadata(array $image, $source_path)
{
  $rotation = 0;
  if (isset($image['rotation']))
  {
    $rotation = pwg_image::get_rotation_angle_from_code($image['rotation']);
  }
  else
  {
    $rotation = pwg_image::get_rotation_angle($source_path);
  }
  return array('rotation'=>$rotation, 'coi'=>$image['coi'] ?? null);
}

function bratonien_tools_cache_builder_generate($source_path, $target_path, $params, array $metadata)
{
  $directory = dirname($target_path);
  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory))
  {
    return false;
  }

  $image = new pwg_image($source_path);
  if (!empty($metadata['rotation']))
  {
    $image->rotate($metadata['rotation']);
  }

  $original_size = array($image->get_width(), $image->get_height());
  $crop_rect = null;
  $scaled_size = null;
  $params->sizing->compute($original_size, $metadata['coi'], $crop_rect, $scaled_size);
  if ($crop_rect)
  {
    $image->crop($crop_rect->width(), $crop_rect->height(), $crop_rect->l, $crop_rect->t);
  }
  if ($scaled_size)
  {
    $image->resize($scaled_size[0], $scaled_size[1]);
  }
  if ($params->sharpen)
  {
    $image->sharpen($params->sharpen);
  }

  $image->write($target_path);
  $image->destroy();
  @chmod($target_path, 0644);
  clearstatcache(true, $target_path);
  return is_file($target_path) && is_readable($target_path);
}

$lock_path = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-main-cache.lock';
$lock = @fopen($lock_path, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB))
{
  if (is_resource($lock))
  {
    fclose($lock);
  }
  fwrite(STDERR, "Ein Cache-Aufbau laeuft bereits.\n");
  exit(0);
}

// Solange die Bratonien-Engine aktiv ist, muss Piwigos natives Wasserzeichen
// fuer alle Derivate neutral bleiben. Der manuelle Cache-Builder erzeugt nur
// Piwigos normalen, unveraenderten Derivatcache.
bratonien_tools_watermark_engine_enabled();

$variants = array();
foreach (ImageStdParams::get_defined_type_map() as $type => $params)
{
  $variants['standard:'.$type] = $params;
}
foreach (ImageStdParams::$custom as $custom_key => $last_used)
{
  $params = bratonien_tools_cache_builder_custom_params($custom_key);
  if ($params)
  {
    $variants['custom:'.$custom_key] = $params;
  }
}

$image_count = (int)pwg_db_fetch_assoc(pwg_query('SELECT COUNT(*) AS cnt FROM '.IMAGES_TABLE))['cnt'];
$total = $image_count * count($variants);
$completed = 0;
$generated = 0;
$cached = 0;
$skipped = 0;
$errors = 0;
$last_write = 0.0;

bratonien_tools_cache_builder_status('running', 'Piwigo-Bildcache wird aufgebaut.', $total, 0, 0, 0, 0, 0, 'Vorbereitung');

$result = pwg_query('SELECT * FROM '.IMAGES_TABLE.' ORDER BY id');
while ($image_row = pwg_db_fetch_assoc($result))
{
  $src = new SrcImage($image_row);
  $source_path = $src->get_path();
  $image_id = (int)$image_row['id'];
  $metadata = null;

  foreach ($variants as $variant_name => $requested_params)
  {
    $completed++;
    $current = 'Bild #'.$image_id.' · '.$variant_name;

    try
    {
      $derivative = new DerivativeImage($requested_params, $src);
      $target_path = $derivative->get_path();

      // Kleine Originale koennen bei Piwigo direkt auf das Original oder auf
      // eine bereits kleinere Standardgroesse zurueckfallen. Dann gibt es fuer
      // diese Anforderung nichts zusaetzlich zu erzeugen.
      if (strpos($target_path, PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR) !== 0)
      {
        $skipped++;
        continue;
      }

      $effective_params = bratonien_tools_cache_builder_effective_params_from_path($target_path);
      if (!$effective_params)
      {
        $skipped++;
        continue;
      }

      if (is_file($target_path) && is_readable($target_path) && @filemtime($target_path) >= (int)$effective_params->last_mod_time)
      {
        $cached++;
      }
      else
      {
        if (!is_file($source_path) || !is_readable($source_path))
        {
          $errors++;
        }
        else
        {
          if ($metadata === null)
          {
            $metadata = bratonien_tools_cache_builder_metadata($image_row, $source_path);
          }
          if (bratonien_tools_cache_builder_generate($source_path, $target_path, $effective_params, $metadata))
          {
            $generated++;
          }
          else
          {
            $errors++;
          }
        }
      }
    }
    catch (Throwable $e)
    {
      $errors++;
      fwrite(STDERR, $current.': '.$e->getMessage()."\n");
    }

    $now = microtime(true);
    if (($now - $last_write) >= 0.3 || $completed >= $total)
    {
      bratonien_tools_cache_builder_status('running', 'Piwigo-Bildcache wird aufgebaut.', $total, $completed, $generated, $cached, $skipped, $errors, $current);
      $last_write = $now;
    }
  }
}

$state = $errors > 0 ? 'error' : 'complete';
$message = $errors > 0
  ? 'Piwigo-Bildcache wurde aufgebaut, einige Varianten konnten jedoch nicht erzeugt werden.'
  : 'Piwigo-Bildcache wurde vollstaendig aufgebaut.';
bratonien_tools_cache_builder_status($state, $message, $total, $completed, $generated, $cached, $skipped, $errors, '');

flock($lock, LOCK_UN);
fclose($lock);
@unlink($lock_path);

printf("Piwigo-Bildcache: %d/%d, %d neu, %d vorhanden, %d uebersprungen, %d Fehler.\n", $completed, $total, $generated, $cached, $skipped, $errors);
exit($errors > 0 ? 1 : 0);
