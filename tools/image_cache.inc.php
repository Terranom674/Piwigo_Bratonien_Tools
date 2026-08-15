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
  $failed_files = 0;

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
    $size = $item->isFile() ? $item->getSize() : 0;

    if (@unlink($path))
    {
      $deleted_files++;
      $deleted_bytes += $size;
    }
    else
    {
      $failed_files++;
    }
  }

  if ($failed_files > 0)
  {
    throw new RuntimeException(
      sprintf('%d Dateien geloescht, %d Dateien konnten nicht geloescht werden.', $deleted_files, $failed_files)
    );
  }

  return array(
    'message' => sprintf(
      'Bildcache geleert: %d Dateien (%s) entfernt.',
      $deleted_files,
      bratonien_tools_format_bytes($deleted_bytes)
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
