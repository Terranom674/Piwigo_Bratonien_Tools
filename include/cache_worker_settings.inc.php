<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_cache_worker_settings_file()
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-cache-workers.json';
}

function bratonien_tools_count_cpu_list($value)
{
  $count = 0;
  foreach (explode(',', trim((string)$value)) as $part)
  {
    $part = trim($part);
    if ($part === '')
    {
      continue;
    }
    if (preg_match('/^(\d+)-(\d+)$/', $part, $m))
    {
      $start = (int)$m[1];
      $end = (int)$m[2];
      if ($end >= $start)
      {
        $count += $end - $start + 1;
      }
    }
    elseif (ctype_digit($part))
    {
      $count++;
    }
  }
  return $count;
}

function bratonien_tools_detect_available_cpu_count()
{
  $counts = array();

  $status = @file_get_contents('/proc/self/status');
  if ($status !== false && preg_match('/^Cpus_allowed_list:\s*(.+)$/mi', $status, $m))
  {
    $allowed = bratonien_tools_count_cpu_list($m[1]);
    if ($allowed > 0)
    {
      $counts[] = $allowed;
    }
  }

  foreach (array('/usr/bin/nproc', '/bin/nproc') as $candidate)
  {
    if (!is_file($candidate) || !is_executable($candidate) || !function_exists('exec'))
    {
      continue;
    }
    $output = array();
    $exit = 1;
    @exec(escapeshellarg($candidate).' 2>/dev/null', $output, $exit);
    if ($exit === 0 && isset($output[0]) && ctype_digit(trim($output[0])))
    {
      $nproc = (int)trim($output[0]);
      if ($nproc > 0)
      {
        $counts[] = $nproc;
      }
    }
    break;
  }

  return empty($counts) ? 1 : max(1, min($counts));
}

function bratonien_tools_get_cache_worker_settings()
{
  $settings = array(
    'auto'=>true,
    'manual_workers'=>6,
    'factor'=>1.0,
    'max_workers'=>32,
  );

  $file = bratonien_tools_cache_worker_settings_file();
  if (is_file($file) && is_readable($file))
  {
    $raw = @file_get_contents($file);
    $saved = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($saved))
    {
      if (array_key_exists('auto', $saved))
      {
        $settings['auto'] = (bool)$saved['auto'];
      }
      if (isset($saved['manual_workers']))
      {
        $settings['manual_workers'] = max(1, min(32, (int)$saved['manual_workers']));
      }
    }
  }

  $settings['cpu_count'] = bratonien_tools_detect_available_cpu_count();
  $settings['auto_workers'] = max(1, min($settings['max_workers'], $settings['cpu_count']));
  $settings['worker_count'] = $settings['auto'] ? $settings['auto_workers'] : $settings['manual_workers'];
  return $settings;
}

function bratonien_tools_save_cache_worker_settings()
{
  $auto = !empty($_POST['cache_workers_auto']);
  $manual = isset($_POST['cache_workers_manual']) ? (int)$_POST['cache_workers_manual'] : 6;
  $manual = max(1, min(32, $manual));

  $json = json_encode(array('auto'=>$auto, 'manual_workers'=>$manual), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $file = bratonien_tools_cache_worker_settings_file();
  if ($json === false || @file_put_contents($file, $json."\n", LOCK_EX) === false)
  {
    throw new RuntimeException('Cache-Worker-Einstellung konnte nicht gespeichert werden.');
  }
  @chmod($file, 0664);

  $settings = bratonien_tools_get_cache_worker_settings();
  return array(
    'message'=>sprintf(
      'Cache-Worker gespeichert: %s. %d CPU(s) erkannt, %d Worker aktiv.',
      $settings['auto'] ? 'Automatik 1:1' : 'manuell',
      $settings['cpu_count'],
      $settings['worker_count']
    ),
  );
}
