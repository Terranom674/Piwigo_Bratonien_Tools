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

function bratonien_tools_detect_visible_cpu_count()
{
  $cpuinfo = @file_get_contents('/proc/cpuinfo');
  if ($cpuinfo !== false)
  {
    $matches = array();
    $count = preg_match_all('/^processor\s*:\s*\d+\s*$/mi', $cpuinfo, $matches);
    if ($count > 0)
    {
      return (int)$count;
    }
  }

  foreach (array('/usr/bin/getconf', '/bin/getconf') as $candidate)
  {
    if (!is_file($candidate) || !is_executable($candidate) || !function_exists('exec'))
    {
      continue;
    }
    $output = array();
    $exit = 1;
    @exec(escapeshellarg($candidate).' _NPROCESSORS_ONLN 2>/dev/null', $output, $exit);
    if ($exit === 0 && isset($output[0]) && ctype_digit(trim($output[0])))
    {
      $count = (int)trim($output[0]);
      if ($count > 0)
      {
        return $count;
      }
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
    @exec(escapeshellarg($candidate).' --all 2>/dev/null', $output, $exit);
    if ($exit === 0 && isset($output[0]) && ctype_digit(trim($output[0])))
    {
      $count = (int)trim($output[0]);
      if ($count > 0)
      {
        return $count;
      }
    }
  }

  return 1;
}

function bratonien_tools_detect_process_cpu_count()
{
  $status = @file_get_contents('/proc/self/status');
  if ($status !== false && preg_match('/^Cpus_allowed_list:\s*(.+)$/mi', $status, $m))
  {
    $allowed = bratonien_tools_count_cpu_list($m[1]);
    if ($allowed > 0)
    {
      return $allowed;
    }
  }
  return bratonien_tools_detect_visible_cpu_count();
}

function bratonien_tools_get_cache_worker_settings()
{
  $settings = array(
    'auto'=>true,
    'manual_workers'=>6,
    'factor'=>1.0,
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
        $settings['manual_workers'] = max(1, (int)$saved['manual_workers']);
      }
    }
  }

  $settings['cpu_count'] = max(1, bratonien_tools_detect_visible_cpu_count());
  $settings['process_cpu_count'] = max(1, bratonien_tools_detect_process_cpu_count());
  $settings['max_workers'] = max(1, $settings['cpu_count'] * 2);
  $settings['auto_workers'] = min($settings['max_workers'], $settings['cpu_count']);
  $settings['manual_workers'] = min($settings['max_workers'], $settings['manual_workers']);
  $settings['worker_count'] = $settings['auto'] ? $settings['auto_workers'] : $settings['manual_workers'];
  return $settings;
}

function bratonien_tools_save_cache_worker_settings()
{
  $current = bratonien_tools_get_cache_worker_settings();
  $max_workers = (int)$current['max_workers'];
  $auto = !empty($_POST['cache_workers_auto']);
  $manual = isset($_POST['cache_workers_manual']) ? (int)$_POST['cache_workers_manual'] : (int)$current['manual_workers'];
  $manual = max(1, min($max_workers, $manual));

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
      'Cache-Worker gespeichert: %s. %d CPU(s) sichtbar, maximal %d Worker, aktuell %d Worker.',
      $settings['auto'] ? 'Automatik 1:1' : 'manuell',
      $settings['cpu_count'],
      $settings['max_workers'],
      $settings['worker_count']
    ),
  );
}
