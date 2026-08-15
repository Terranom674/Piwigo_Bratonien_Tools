<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_clear_image_cache()
{
  if (!defined('PWG_DERIVATIVE_DIR'))
  {
    throw new RuntimeException('PWG_DERIVATIVE_DIR ist nicht definiert.');
  }

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

  bratonien_tools_request_watermark_precache();

  return array(
    'message' => sprintf(
      'Bildcache vollstaendig geleert: %d Dateien (%s) entfernt, davon %d Custom-Derivate. %d Restdateien wurden zusaetzlich ausserhalb von Piwigos Standard-Loeschmustern entfernt. Wasserzeichen-Precache wurde vorgemerkt.',
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

function bratonien_tools_request_watermark_precache()
{
  $request_file = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-tools-precache.request';
  $directory = dirname($request_file);

  if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory))
  {
    throw new RuntimeException('Precache-Anforderung konnte nicht vorbereitet werden.');
  }

  if (@file_put_contents($request_file, (string)time()."\n", LOCK_EX) === false)
  {
    throw new RuntimeException('Precache-Anforderung konnte nicht gespeichert werden.');
  }

  @chmod($request_file, 0664);
  return $request_file;
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
