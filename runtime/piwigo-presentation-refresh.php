<?php
if (PHP_SAPI !== 'cli')
{
  http_response_code(404);
  exit;
}

$piwigo_root = realpath(dirname(__DIR__, 3));
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
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/piwigo-presentation-refresh.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-Presentation-Refresh/0.9.7.1.41';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');
if (!defined('BRATONIEN_TOOLS_PATH'))
{
  fwrite(STDERR, "Bratonien Tools ist nicht aktiv.\n");
  exit(1);
}
require_once(BRATONIEN_TOOLS_PATH.'include/presentation_refresh.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/watermark_runtime.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_materialize_runtime.inc.php');

function bratonien_tools_presentation_worker_log($event, array $data=array())
{
  $parts = array('[BRAT-PRESENTATION]', $event);
  foreach ($data as $key=>$value)
  {
    if (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $parts[] = $key.'='.str_replace(array("\r", "\n"), array('\\r', '\\n'), (string)$value);
  }
  fwrite(STDOUT, implode(' ', $parts)."\n");
}

function bratonien_tools_presentation_worker_categories(array $requested, $all)
{
  $requested = array_fill_keys(array_map('intval', $requested), true);
  $categories = array();
  $result = pwg_query('SELECT id, uppercats FROM '.CATEGORIES_TABLE.' ORDER BY id');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $id = (int)$row['id'];
    if ($id < 1) continue;
    if ($all)
    {
      $categories[$id] = $id;
      continue;
    }

    $uppercats = array_filter(array_map('intval', explode(',', (string)$row['uppercats'])));
    foreach ($uppercats as $ancestor)
    {
      if (isset($requested[$ancestor]))
      {
        $categories[$id] = $id;
        break;
      }
    }
  }
  ksort($categories, SORT_NUMERIC);
  return array_values($categories);
}

function bratonien_tools_presentation_worker_variants(SrcImage $src)
{
  $variants = array();
  foreach (ImageStdParams::get_defined_type_map() as $type=>$params)
  {
    $variants['standard:'.$type] = new DerivativeImage($params, $src);
  }
  foreach (ImageStdParams::$custom as $key=>$last_used)
  {
    $params = bratonien_tools_webdav_materialize_custom_params($key);
    if ($params) $variants['custom:'.$key] = new DerivativeImage($params, $src);
  }
  return $variants;
}

function bratonien_tools_presentation_worker_call_watermark($url, &$detail=null)
{
  $detail = '';
  $url = html_entity_decode((string)$url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $path = (string)parse_url($url, PHP_URL_PATH);
  $query = (string)parse_url($url, PHP_URL_QUERY);
  $endpoint = '/plugins/'.trim(BRATONIEN_TOOLS_ID, '/').'/watermark.php';

  if ($path === '' || substr($path, -strlen($endpoint)) !== $endpoint)
  {
    if (strpos($path, '/bratonien-watermark/') !== false)
    {
      $detail = 'Bereits vorhanden.';
      return true;
    }
    $detail = 'Kein Bratonien-Wasserzeichenaufruf erforderlich.';
    return true;
  }
  if ($query === '')
  {
    $detail = 'Wasserzeichen-URL enthält keine Parameter.';
    return false;
  }
  if (!function_exists('exec'))
  {
    $detail = 'PHP exec() ist deaktiviert.';
    return false;
  }

  $runner = BRATONIEN_TOOLS_PATH.'runtime/lib/bratonien-watermark-call.php';
  if (!is_file($runner))
  {
    $detail = 'Lokaler Wasserzeichen-Aufrufer fehlt.';
    return false;
  }

  $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($runner)
    .' --query='.escapeshellarg($query).' > /dev/null 2>&1';
  $output = array();
  $exit = 1;
  @exec($command, $output, $exit);
  if ($exit !== 0)
  {
    $detail = 'Wasserzeichen-Aufrufer Exit '.$exit.'.';
    return false;
  }

  $detail = 'Wasserzeichen-Vorschau bereit.';
  return true;
}

function bratonien_tools_presentation_worker_process(array $category_ids)
{
  global $page;

  if (!$category_ids) return array('images'=>0, 'profiles'=>0, 'variants'=>0, 'generated'=>0, 'missing_base'=>0, 'errors'=>0);
  $category_lookup = array_fill_keys($category_ids, true);
  $ids_sql = implode(',', array_map('intval', $category_ids));

  $image_categories = array();
  $result = pwg_query('SELECT image_id, category_id FROM '.IMAGE_CATEGORY_TABLE.' WHERE category_id IN ('.$ids_sql.') ORDER BY image_id, category_id');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $image_id = (int)$row['image_id'];
    $category_id = (int)$row['category_id'];
    if ($image_id > 0 && isset($category_lookup[$category_id])) $image_categories[$image_id][] = $category_id;
  }
  if (!$image_categories) return array('images'=>0, 'profiles'=>0, 'variants'=>0, 'generated'=>0, 'missing_base'=>0, 'errors'=>0);

  $stats = array('images'=>0, 'profiles'=>0, 'variants'=>0, 'generated'=>0, 'missing_base'=>0, 'errors'=>0);
  $image_ids = array_keys($image_categories);
  foreach (array_chunk($image_ids, 500) as $chunk)
  {
    $rows = pwg_query('SELECT * FROM '.IMAGES_TABLE.' WHERE id IN ('.implode(',', array_map('intval', $chunk)).') ORDER BY id');
    while ($row = pwg_db_fetch_assoc($rows))
    {
      $image_id = (int)$row['id'];
      $stats['images']++;
      $profile_contexts = array();

      foreach ($image_categories[$image_id] ?? array() as $category_id)
      {
        $rule = bratonien_tools_runtime_effective_rule($category_id);
        if ((string)($rule['mode'] ?? '') !== 'profile' || empty($rule['profile_id'])) continue;
        $profile_id = (int)$rule['profile_id'];
        if ($profile_id < 1 || isset($profile_contexts[$profile_id])) continue;
        $profile = bratonien_tools_runtime_get_profile($profile_id);
        if (!$profile || empty($profile['active']) || !bratonien_tools_profile_watermark_path($profile)) continue;
        $profile_contexts[$profile_id] = $category_id;
      }

      if (!$profile_contexts) continue;

      try
      {
        $src = new SrcImage($row);
        $variants = bratonien_tools_presentation_worker_variants($src);
      }
      catch (Throwable $e)
      {
        $stats['errors']++;
        bratonien_tools_presentation_worker_log('image_error', array('image_id'=>$image_id, 'message'=>$e->getMessage()));
        continue;
      }

      foreach ($profile_contexts as $profile_id=>$category_id)
      {
        $stats['profiles']++;
        $page['category'] = array('id'=>(int)$category_id);

        foreach ($variants as $variant_name=>$derivative)
        {
          try
          {
            if ($derivative->same_as_source()) continue;
            $stats['variants']++;
            $target = $derivative->get_path();
            if ($target === '' || !is_file($target) || !is_readable($target))
            {
              $stats['missing_base']++;
              continue;
            }

            $detail = '';
            if (!bratonien_tools_presentation_worker_call_watermark($derivative->get_url(), $detail))
            {
              $stats['errors']++;
              bratonien_tools_presentation_worker_log('variant_error', array(
                'image_id'=>$image_id,
                'category_id'=>$category_id,
                'profile_id'=>$profile_id,
                'variant'=>$variant_name,
                'message'=>$detail,
              ));
              continue;
            }
            $stats['generated']++;
          }
          catch (Throwable $e)
          {
            $stats['errors']++;
            bratonien_tools_presentation_worker_log('variant_error', array(
              'image_id'=>$image_id,
              'category_id'=>$category_id,
              'profile_id'=>$profile_id,
              'variant'=>$variant_name,
              'message'=>$e->getMessage(),
            ));
          }
        }
      }
    }
  }

  unset($page['category']);
  return $stats;
}

try
{
  $queue_dir = bratonien_tools_presentation_refresh_queue_dir();
  if (!is_dir($queue_dir)) exit(0);

  $lock_path = $queue_dir.'/.worker.lock';
  $lock = @fopen($lock_path, 'c');
  if (!$lock) throw new RuntimeException('Presentation-Worker-Lock konnte nicht geöffnet werden.');
  if (!@flock($lock, LOCK_EX)) throw new RuntimeException('Presentation-Worker-Lock konnte nicht gesetzt werden.');

  try
  {
    while (true)
    {
      $request_files = glob($queue_dir.'/*.json') ?: array();
      sort($request_files, SORT_STRING);
      if (!$request_files) break;

      $all = false;
      $requested = array();
      $valid_files = array();
      $reasons = array();
      foreach ($request_files as $file)
      {
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data))
        {
          bratonien_tools_presentation_worker_log('invalid_request', array('file'=>basename($file)));
          @unlink($file);
          continue;
        }
        $valid_files[] = $file;
        if (!empty($data['all'])) $all = true;
        foreach ((array)($data['categories'] ?? array()) as $category_id)
        {
          $category_id = (int)$category_id;
          if ($category_id > 0) $requested[$category_id] = $category_id;
        }
        if (!empty($data['reason'])) $reasons[] = (string)$data['reason'];
      }

      if (!$valid_files) continue;
      $categories = bratonien_tools_presentation_worker_categories(array_values($requested), $all);
      $stats = bratonien_tools_presentation_worker_process($categories);
      bratonien_tools_presentation_worker_log('complete', array(
        'all'=>$all,
        'requested'=>count($requested),
        'categories'=>count($categories),
        'reasons'=>array_values(array_unique($reasons)),
        'images'=>$stats['images'],
        'profiles'=>$stats['profiles'],
        'variants'=>$stats['variants'],
        'prepared'=>$stats['generated'],
        'missing_base'=>$stats['missing_base'],
        'errors'=>$stats['errors'],
      ));

      foreach ($valid_files as $file) @unlink($file);
    }
  }
  finally
  {
    @flock($lock, LOCK_UN);
    fclose($lock);
  }
  exit(0);
}
catch (Throwable $e)
{
  fwrite(STDERR, '[BRAT-PRESENTATION] fatal '.$e->getMessage()."\n");
  exit(1);
}
