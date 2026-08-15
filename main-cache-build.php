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
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-Piwigo-Cache-Builder/1.1';
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

function bratonien_tools_cache_builder_status($file, $state, $message, $total=0, $completed=0, $generated=0, $cached=0, $skipped=0, $errors=0, $current='')
{
  $payload = array(
    'state'=>$state,
    'message'=>$message,
    'total'=>(int)$total,
    'completed'=>(int)$completed,
    'generated'=>(int)$generated,
    'cached'=>(int)$cached,
    'skipped'=>(int)$skipped,
    'errors'=>(int)$errors,
    'current'=>(string)$current,
    'updated_at'=>time(),
  );
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false || @file_put_contents($file, $json."\n", LOCK_EX) === false)
  {
    throw new RuntimeException('Worker-Status konnte nicht gespeichert werden.');
  }
  @chmod($file, 0664);
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
  $rotation = isset($image['rotation'])
    ? pwg_image::get_rotation_angle_from_code($image['rotation'])
    : pwg_image::get_rotation_angle($source_path);
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

function bratonien_tools_cache_builder_variants()
{
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
  return $variants;
}

function bratonien_tools_cache_builder_worker($worker_index, $worker_count, $run_id)
{
  $status_file = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-main-cache.worker-'.$run_id.'-'.$worker_index.'.json';
  $variants = bratonien_tools_cache_builder_variants();
  $rows = array();
  $result = pwg_query('SELECT * FROM '.IMAGES_TABLE.' ORDER BY id');
  while ($row = pwg_db_fetch_assoc($result))
  {
    if (((int)$row['id'] % $worker_count) === $worker_index)
    {
      $rows[] = $row;
    }
  }

  $total = count($rows) * count($variants);
  $completed = 0;
  $generated = 0;
  $cached = 0;
  $skipped = 0;
  $errors = 0;
  $last_write = 0.0;

  bratonien_tools_cache_builder_status($status_file, 'running', 'Worker '.($worker_index + 1).' arbeitet.', $total, 0, 0, 0, 0, 0, 'Vorbereitung');

  foreach ($rows as $image_row)
  {
    $src = new SrcImage($image_row);
    $source_path = $src->get_path();
    $image_id = (int)$image_row['id'];
    $metadata = null;

    foreach ($variants as $variant_name => $requested_params)
    {
      $completed++;
      $current = 'Worker '.($worker_index + 1).' · Bild #'.$image_id.' · '.$variant_name;

      try
      {
        $derivative = new DerivativeImage($requested_params, $src);
        $target_path = $derivative->get_path();
        if (strpos($target_path, PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR) !== 0)
        {
          $skipped++;
        }
        else
        {
          $effective_params = bratonien_tools_cache_builder_effective_params_from_path($target_path);
          if (!$effective_params)
          {
            $skipped++;
          }
          elseif (is_file($target_path) && is_readable($target_path) && @filemtime($target_path) >= (int)$effective_params->last_mod_time)
          {
            $cached++;
          }
          elseif (!is_file($source_path) || !is_readable($source_path))
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
        bratonien_tools_cache_builder_status($status_file, 'running', 'Worker '.($worker_index + 1).' arbeitet.', $total, $completed, $generated, $cached, $skipped, $errors, $current);
        $last_write = $now;
      }
    }
  }

  $state = $errors > 0 ? 'error' : 'complete';
  bratonien_tools_cache_builder_status(
    $status_file,
    $state,
    $errors > 0 ? 'Worker '.($worker_index + 1).' mit Fehlern beendet.' : 'Worker '.($worker_index + 1).' beendet.',
    $total, $completed, $generated, $cached, $skipped, $errors, ''
  );
  exit($errors > 0 ? 1 : 0);
}

function bratonien_tools_cache_builder_read_worker($file)
{
  if (!is_file($file) || !is_readable($file))
  {
    return null;
  }
  $raw = @file_get_contents($file);
  $data = $raw !== false ? json_decode($raw, true) : null;
  return is_array($data) ? $data : null;
}

function bratonien_tools_cache_builder_aggregate($run_id, $worker_count, $message)
{
  $sum = array('total'=>0,'completed'=>0,'generated'=>0,'cached'=>0,'skipped'=>0,'errors'=>0);
  $current = array();
  $states = array();

  for ($i=0; $i<$worker_count; $i++)
  {
    $file = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-main-cache.worker-'.$run_id.'-'.$i.'.json';
    $data = bratonien_tools_cache_builder_read_worker($file);
    if (!$data)
    {
      continue;
    }
    $states[] = (string)($data['state'] ?? 'running');
    foreach ($sum as $key => $value)
    {
      $sum[$key] += (int)($data[$key] ?? 0);
    }
    if (!empty($data['current']))
    {
      $current[] = $data['current'];
    }
  }

  $state = 'running';
  if (count($states) === $worker_count)
  {
    $all_done = true;
    foreach ($states as $worker_state)
    {
      if ($worker_state === 'running' || $worker_state === 'queued')
      {
        $all_done = false;
        break;
      }
    }
    if ($all_done)
    {
      $state = $sum['errors'] > 0 || in_array('error', $states, true) ? 'error' : 'complete';
    }
  }

  bratonien_tools_write_main_cache_status(array(
    'state'=>$state,
    'message'=>$message,
    'total'=>$sum['total'],
    'completed'=>$sum['completed'],
    'generated'=>$sum['generated'],
    'cached'=>$sum['cached'],
    'skipped'=>$sum['skipped'],
    'errors'=>$sum['errors'],
    'current'=>implode(' | ', array_slice($current, 0, 4)),
  ));
  return $state;
}

$worker_index = null;
$worker_count = 4;
$run_id = '';
foreach ($argv as $arg)
{
  if (preg_match('/^--worker=(\d+)$/', $arg, $m))
  {
    $worker_index = (int)$m[1];
  }
  elseif (preg_match('/^--workers=(\d+)$/', $arg, $m))
  {
    $worker_count = max(1, (int)$m[1]);
  }
  elseif (preg_match('/^--run=([A-Za-z0-9_-]+)$/', $arg, $m))
  {
    $run_id = $m[1];
  }
}

if ($worker_index !== null)
{
  if ($run_id === '' || $worker_index < 0 || $worker_index >= $worker_count)
  {
    fwrite(STDERR, "Ungueltige Worker-Parameter.\n");
    exit(1);
  }
  bratonien_tools_cache_builder_worker($worker_index, $worker_count, $run_id);
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

bratonien_tools_watermark_engine_enabled();

if (!function_exists('proc_open'))
{
  bratonien_tools_write_main_cache_status(array('state'=>'error','message'=>'proc_open() ist deaktiviert; vier parallele Worker koennen nicht gestartet werden.','errors'=>1));
  flock($lock, LOCK_UN);
  fclose($lock);
  exit(1);
}

$run_id = date('YmdHis').'-'.getmypid();
$log = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-main-cache.log';
$processes = array();

for ($i=0; $i<$worker_count; $i++)
{
  $status_file = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-main-cache.worker-'.$run_id.'-'.$i.'.json';
  @unlink($status_file);
  bratonien_tools_cache_builder_status($status_file, 'queued', 'Worker '.($i + 1).' wartet.', 0, 0, 0, 0, 0, 0, '');

  $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --worker='.$i.' --workers='.$worker_count.' --run='.escapeshellarg($run_id);
  $descriptors = array(
    0=>array('file','/dev/null','r'),
    1=>array('file',$log,'a'),
    2=>array('file',$log,'a'),
  );
  $process = @proc_open($command, $descriptors, $pipes);
  if (!is_resource($process))
  {
    bratonien_tools_cache_builder_status($status_file, 'error', 'Worker '.($i + 1).' konnte nicht gestartet werden.', 0, 0, 0, 0, 0, 1, '');
  }
  else
  {
    $processes[$i] = $process;
  }
}

bratonien_tools_write_main_cache_status(array(
  'state'=>'running',
  'message'=>'Piwigo-Bildcache wird mit 4 parallelen Workern aufgebaut.',
  'current'=>'Worker werden gestartet.',
));

while (true)
{
  $running = false;
  foreach ($processes as $process)
  {
    $info = proc_get_status($process);
    if (!empty($info['running']))
    {
      $running = true;
    }
  }

  $state = bratonien_tools_cache_builder_aggregate($run_id, $worker_count, 'Piwigo-Bildcache wird mit 4 parallelen Workern aufgebaut.');
  if (!$running && ($state === 'complete' || $state === 'error'))
  {
    break;
  }
  usleep(500000);
}

$exit_error = false;
foreach ($processes as $process)
{
  $code = proc_close($process);
  if ($code !== 0 && $code !== -1)
  {
    $exit_error = true;
  }
}

$state = bratonien_tools_cache_builder_aggregate(
  $run_id,
  $worker_count,
  $exit_error ? 'Piwigo-Bildcache beendet; mindestens ein Worker meldete einen Prozessfehler.' : 'Piwigo-Bildcache wurde aufgebaut.'
);

for ($i=0; $i<$worker_count; $i++)
{
  @unlink(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-main-cache.worker-'.$run_id.'-'.$i.'.json');
}

flock($lock, LOCK_UN);
fclose($lock);
@unlink($lock_path);

exit(($state === 'error' || $exit_error) ? 1 : 0);
