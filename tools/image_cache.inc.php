<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_clear_image_cache()
{
  $cache_root = rtrim(PHPWG_ROOT_PATH, '/\\') . '/_data/i';

  if (!is_dir($cache_root))
  {
    throw new RuntimeException('Bildcache-Verzeichnis wurde nicht gefunden: ' . $cache_root);
  }

  $real_cache_root = realpath($cache_root);
  $expected_root = realpath(rtrim(PHPWG_ROOT_PATH, '/\\') . '/_data/i');

  if ($real_cache_root === false || $expected_root === false || $real_cache_root !== $expected_root)
  {
    throw new RuntimeException('Sicherheitspruefung des Bildcache-Pfads fehlgeschlagen.');
  }

  $deleted_files = 0;
  $deleted_bytes = 0;
  $deleted_custom = 0;
  $failed_files = array();

  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($real_cache_root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
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

    $path = $item->getPathname();
    $filename = $item->getFilename();
    $size = $item->isFile() ? $item->getSize() : 0;
    $is_custom = strpos($filename, '-cu_') !== false;

    if (@unlink($path))
    {
      $deleted_files++;
      $deleted_bytes += $size;
      if ($is_custom)
      {
        $deleted_custom++;
      }
    }
    else
    {
      $failed_files[] = $path;
    }
  }

  if (!empty($failed_files))
  {
    throw new RuntimeException(
      sprintf(
        '%d Dateien geloescht, %d Dateien konnten nicht geloescht werden. Erste problematische Datei: %s',
        $deleted_files,
        count($failed_files),
        $failed_files[0]
      )
    );
  }

  // Sicherheitskontrolle: Nach dem Leeren darf kein Custom-Derivat mehr
  // im Piwigo-Bildcache liegen. Damit werden insbesondere gdThumb-Dateien
  // wie *-cu_s9999x250.jpg erfasst.
  $remaining_custom = array();
  $verify_iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($real_cache_root, FilesystemIterator::SKIP_DOTS)
  );

  foreach ($verify_iterator as $item)
  {
    if ($item->isFile() && strpos($item->getFilename(), '-cu_') !== false)
    {
      $remaining_custom[] = $item->getPathname();
      if (count($remaining_custom) >= 5)
      {
        break;
      }
    }
  }

  if (!empty($remaining_custom))
  {
    throw new RuntimeException(
      'Bildcache wurde nicht vollstaendig geleert. Verbleibendes Custom-Derivat: ' . $remaining_custom[0]
    );
  }

  return array(
    'message' => sprintf(
      'Bildcache geleert: %d Dateien (%s) entfernt, davon %d Custom-Derivate.',
      $deleted_files,
      bratonien_tools_format_bytes($deleted_bytes),
      $deleted_custom
    ),
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
