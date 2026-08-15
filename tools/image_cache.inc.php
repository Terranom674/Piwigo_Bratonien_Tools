<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_main_cache_status_file()
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-main-cache.status.json';
}

function bratonien_tools_main_cache_cancel_file()
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-main-cache.cancel';
}

function bratonien_tools_main_cache_lock_file()
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-main-cache.lock';
}

function bratonien_tools_write_main_cache_status(array $status)
{
  $file = bratonien_tools_main_cache_status_file();
  $directory = dirname($file);
  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory))
  {
    throw new RuntimeException('Cache-Status konnte nicht vorbereitet werden.');
  }

  $payload = array_merge(array(
    'state'=>'idle',
    'message'=>'',
    'total'=>0,
    'completed'=>0,
    'generated'=>0,
    'cached'=>0,
    'skipped'=>0,
    'errors'=>0,
    'current'=>'',
    'updated_at'=>time(),
  ), $status);
  $payload['updated_at'] = time();

  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false || @file_put_contents($file, $json."\n", LOCK_EX) === false)
  {
    throw new RuntimeException('Cache-Status konnte nicht gespeichert werden.');
  }
  @chmod($file, 0664);
}

function bratonien_tools_read_main_cache_status()
{
  $file = bratonien_tools_main_cache_status_file();
  if (!is_file($file) || !is_readable($file))
  {
    return null;
  }
  $raw = @file_get_contents($file);
  $status = $raw !== false ? json_decode($raw, true) : null;
  return is_array($status) ? $status : null;
}

function bratonien_tools_main_cache_is_running()
{
  $status = bratonien_tools_read_main_cache_status();
  if (!is_array($status) || !in_array(($status['state'] ?? ''), array('queued','running','cancelling'), true))
  {
    return false;
  }

  $updated = (int)($status['updated_at'] ?? 0);
  return $updated > 0 && (time() - $updated) < 45;
}

function bratonien_tools_main_cache_process_active()
{
  $lock_path = bratonien_tools_main_cache_lock_file();
  $lock = @fopen($lock_path, 'c');
  if (!$lock)
  {
    return bratonien_tools_main_cache_is_running();
  }

  $free = @flock($lock, LOCK_EX | LOCK_NB);
  if ($free)
  {
    @flock($lock, LOCK_UN);
  }
  fclose($lock);
  return !$free;
}

function bratonien_tools_request_main_cache_cancel()
{
  $cancel = bratonien_tools_main_cache_cancel_file();
  $directory = dirname($cancel);
  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory))
  {
    throw new RuntimeException('Cache-Abbruch konnte nicht vorbereitet werden.');
  }

  if (@file_put_contents($cancel, (string)time()."\n", LOCK_EX) === false)
  {
    throw new RuntimeException('Cache-Abbruchsignal konnte nicht geschrieben werden.');
  }
  @chmod($cancel, 0664);

  bratonien_tools_write_main_cache_status(array(
    'state'=>'cancelling',
    'message'=>'Cache-Aufbau wird abgebrochen.',
  ));
}

function bratonien_tools_wait_main_cache_stopped($timeout_seconds=10.0)
{
  $deadline = microtime(true) + max(0.1, (float)$timeout_seconds);
  do
  {
    if (!bratonien_tools_main_cache_process_active())
    {
      return true;
    }
    usleep(100000);
  }
  while (microtime(true) < $deadline);

  return !bratonien_tools_main_cache_process_active();
}

function bratonien_tools_cancel_main_cache_build()
{
  if (!bratonien_tools_main_cache_process_active() && !bratonien_tools_main_cache_is_running())
  {
    @unlink(bratonien_tools_main_cache_cancel_file());
    bratonien_tools_write_main_cache_status(array(
      'state'=>'idle',
      'message'=>'Kein Cache-Aufbau aktiv.',
    ));
    return array('message'=>'Es läuft kein Piwigo-Bildcache-Aufbau.');
  }

  bratonien_tools_request_main_cache_cancel();

  if (!bratonien_tools_wait_main_cache_stopped(10.0))
  {
    return array('message'=>'Abbruch wurde angefordert. Ein Worker beendet noch die aktuell laufende Bildoperation.');
  }

  @unlink(bratonien_tools_main_cache_cancel_file());
  bratonien_tools_write_main_cache_status(array(
    'state'=>'cancelled',
    'message'=>'Piwigo-Bildcache-Aufbau wurde abgebrochen.',
  ));

  return array('message'=>'Piwigo-Bildcache-Aufbau wurde abgebrochen.');
}

function bratonien_tools_clear_image_cache()
{
  if (!defined('PWG_DERIVATIVE_DIR'))
  {
    throw new RuntimeException('PWG_DERIVATIVE_DIR ist nicht definiert.');
  }

  if (bratonien_tools_main_cache_process_active() || bratonien_tools_main_cache_is_running())
  {
    bratonien_tools_request_main_cache_cancel();
    if (!bratonien_tools_wait_main_cache_stopped(10.0))
    {
      throw new RuntimeException('Der laufende Cache-Aufbau konnte noch nicht beendet werden. Bitte den Abbruch kurz abschließen lassen und erneut leeren.');
    }
  }

  @unlink(bratonien_tools_main_cache_cancel_file());

  $cache_root = PHPWG_ROOT_PATH . PWG_DERIVATIVE_DIR;

  if (!is_dir($cache_root))
  {
    throw new RuntimeException('Bildcache-Verzeichnis wurde nicht gefunden: ' . $cache_root);
  }

  $real_cache_root = realpath($cache_root);
  $real_piwigo_root = realpath(PHPWG_ROOT_PATH);

  if ($real_cache_root === false || $real_piwigo_root === false)
  {
    throw new RuntimeException('Sicherheitspruefung des Bildcache-Pfads fehlgeschlagen.');
  }

  $root_prefix = rtrim($real_piwigo_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
  if (strpos($real_cache_root . DIRECTORY_SEPARATOR, $root_prefix) !== 0)
  {
    throw new RuntimeException('Bildcache liegt ausserhalb der Piwigo-Installation. Abbruch.');
  }

  $before = bratonien_tools_scan_image_cache($real_cache_root);

  if (!function_exists('clear_derivative_cache'))
  {
    require_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');
  }

  if (!function_exists('clear_derivative_cache'))
  {
    throw new RuntimeException('Piwigos Funktion clear_derivative_cache() ist nicht verfuegbar.');
  }

  clear_derivative_cache('all');

  $failed_files = array();
  $residual_deleted = 0;

  if (is_dir($real_cache_root))
  {
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($real_cache_root, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item)
    {
      $path = $item->getPathname();

      if ($item->isDir())
      {
        @rmdir($path);
        continue;
      }

      if (strtolower($item->getFilename()) === 'index.htm')
      {
        continue;
      }

      if (@unlink($path))
      {
        $residual_deleted++;
      }
      else
      {
        $failed_files[] = $path;
      }
    }
  }

  if (!empty($failed_files))
  {
    throw new RuntimeException(
      sprintf(
        '%d Dateien konnten nicht geloescht werden. Erste problematische Datei: %s',
        count($failed_files),
        $failed_files[0]
      )
    );
  }

  $after = bratonien_tools_scan_image_cache($real_cache_root);

  if ($after['files'] > 0)
  {
    throw new RuntimeException(
      sprintf(
        'Bildcache wurde nicht vollstaendig geleert. Noch %d Datei(en) vorhanden. Erste Datei: %s',
        $after['files'],
        $after['first_file']
      )
    );
  }

  bratonien_tools_write_main_cache_status(array(
    'state'=>'idle',
    'message'=>'Bildcache wurde geleert. Kein manueller Cache-Aufbau aktiv.',
  ));

  return array(
    'message' => sprintf(
      'Bildcache vollstaendig geleert: %d Dateien (%s) entfernt, davon %d Custom-Derivate. %d Restdateien wurden zusaetzlich ausserhalb von Piwigos Standard-Loeschmustern entfernt. Ein laufender manueller Cache-Aufbau wurde zuvor beendet.',
      $before['files'],
      bratonien_tools_format_bytes($before['bytes']),
      $before['custom'],
      $residual_deleted
    ),
  );
}

function bratonien_tools_scan_image_cache($cache_root)
{
  $result = array(
    'files' => 0,
    'bytes' => 0,
    'custom' => 0,
    'first_file' => '',
  );

  if (!is_dir($cache_root))
  {
    return $result;
  }

  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($cache_root, FilesystemIterator::SKIP_DOTS)
  );

  foreach ($iterator as $item)
  {
    if (!$item->isFile() && !$item->isLink())
    {
      continue;
    }

    if (strtolower($item->getFilename()) === 'index.htm')
    {
      continue;
    }

    $result['files']++;
    if ($item->isFile())
    {
      $result['bytes'] += $item->getSize();
    }

    if (strpos($item->getFilename(), '-cu_') !== false)
    {
      $result['custom']++;
    }

    if ($result['first_file'] === '')
    {
      $result['first_file'] = $item->getPathname();
    }
  }

  return $result;
}

function bratonien_tools_find_php_cli()
{
  foreach (array('/usr/bin/php', '/usr/bin/php8.4', '/usr/local/bin/php') as $candidate)
  {
    if (is_file($candidate) && is_executable($candidate))
    {
      return $candidate;
    }
  }
  return null;
}

function bratonien_tools_start_main_cache_build()
{
  if (bratonien_tools_main_cache_process_active() || bratonien_tools_main_cache_is_running())
  {
    return array('started'=>false, 'running'=>true, 'message'=>'Der Piwigo-Bildcache wird bereits aufgebaut.');
  }

  @unlink(bratonien_tools_main_cache_cancel_file());

  $worker = realpath(BRATONIEN_TOOLS_PATH.'main-cache-build.php');
  $php = bratonien_tools_find_php_cli();
  if (!$worker || !is_file($worker))
  {
    throw new RuntimeException('Cache-Worker wurde nicht gefunden.');
  }
  if (!$php)
  {
    throw new RuntimeException('PHP-CLI wurde nicht gefunden.');
  }
  if (!function_exists('exec'))
  {
    throw new RuntimeException('PHP exec() ist deaktiviert; Cache-Worker kann nicht gestartet werden.');
  }

  bratonien_tools_write_main_cache_status(array(
    'state'=>'queued',
    'message'=>'Piwigo-Bildcache wird mit 6 Workern vorbereitet.',
  ));

  $log = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-main-cache.log';
  $command = 'nohup '.escapeshellarg($php).' '.escapeshellarg($worker).' >> '.escapeshellarg($log).' 2>&1 < /dev/null & echo $!';
  $output = array();
  $exit = 1;
  @exec($command, $output, $exit);
  $pid = isset($output[0]) ? (int)$output[0] : 0;

  if ($exit !== 0 || $pid <= 0)
  {
    bratonien_tools_write_main_cache_status(array(
      'state'=>'error',
      'message'=>'Piwigo-Bildcache konnte nicht gestartet werden.',
      'errors'=>1,
    ));
    throw new RuntimeException('Piwigo-Bildcache konnte nicht gestartet werden.');
  }

  return array(
    'started'=>true,
    'pid'=>$pid,
    'message'=>'Piwigo-Bildcache wurde manuell mit 6 Workern gestartet.',
  );
}

function bratonien_tools_format_bytes($bytes)
{
  $units = array('B', 'KB', 'MB', 'GB', 'TB');
  $value = (float) $bytes;
  $unit = 0;

  while ($value >= 1024 && $unit < count($units) - 1)
  {
    $value /= 1024;
    $unit++;
  }

  return number_format($value, $unit === 0 ? 0 : 2, ',', '.') . ' ' . $units[$unit];
}
