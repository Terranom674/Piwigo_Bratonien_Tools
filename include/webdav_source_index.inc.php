<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_webdav_source_index_file($state_dir)
{
  $state_dir = rtrim((string)$state_dir, '/');
  if ($state_dir === '') throw new RuntimeException('Connector-State-Verzeichnis fehlt für den WebDAV-Quellenindex.');
  return $state_dir.'/webdav-cache-index.json';
}

function bratonien_tools_webdav_source_index_empty($connection_id)
{
  return array(
    'schema_version'=>2,
    'connection_id'=>(int)$connection_id,
    'updated_at'=>0,
    'last_periodic_at'=>0,
    'sources'=>array(),
  );
}

function bratonien_tools_webdav_source_index_load($state_dir, $connection_id)
{
  $file = bratonien_tools_webdav_source_index_file($state_dir);
  if (!is_file($file) || !is_readable($file)) return null;

  $raw = @file_get_contents($file);
  $index = $raw !== false ? json_decode($raw, true) : null;
  if (!is_array($index)) return null;

  $base = bratonien_tools_webdav_source_index_empty($connection_id);
  $index = array_merge($base, $index);
  if (!isset($index['sources']) || !is_array($index['sources'])) $index['sources'] = array();
  $index['connection_id'] = (int)$connection_id;
  return $index;
}

function bratonien_tools_webdav_source_index_save($state_dir, $connection_id, array $index)
{
  $file = bratonien_tools_webdav_source_index_file($state_dir);
  $directory = dirname($file);
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory))
  {
    throw new RuntimeException('WebDAV-Quellenindex-Verzeichnis konnte nicht angelegt werden.');
  }

  $index['schema_version'] = 2;
  $index['connection_id'] = (int)$connection_id;
  $index['updated_at'] = time();
  if (!isset($index['sources']) || !is_array($index['sources'])) $index['sources'] = array();
  ksort($index['sources'], SORT_STRING);

  $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('WebDAV-Quellenindex konnte nicht serialisiert werden.');

  $tmp = $file.'.tmp-'.bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, $json."\n", LOCK_EX) === false)
  {
    throw new RuntimeException('WebDAV-Quellenindex konnte nicht geschrieben werden.');
  }
  @chmod($tmp, 0664);
  if (!@rename($tmp, $file))
  {
    @unlink($tmp);
    throw new RuntimeException('WebDAV-Quellenindex konnte nicht atomar gespeichert werden.');
  }
}

function bratonien_tools_webdav_source_index_key(array $source)
{
  $connection_id = (int)($source['connection_id'] ?? 0);
  $fileid = (int)($source['fileid'] ?? 0);
  if ($fileid > 0) return 'c'.$connection_id.':f'.$fileid;

  $root_fileid = (int)($source['root_fileid'] ?? 0);
  $path = trim((string)($source['webdav_path'] ?? ''), '/');
  return 'c'.$connection_id.':r'.$root_fileid.':p'.sha1($path);
}

function bratonien_tools_webdav_source_index_signature(array $source)
{
  return sha1(implode('|', array(
    (int)($source['connection_id'] ?? 0),
    (int)($source['root_fileid'] ?? 0),
    (int)($source['fileid'] ?? 0),
    trim((string)($source['webdav_path'] ?? ''), '/'),
    (string)($source['etag'] ?? ''),
    (int)($source['size'] ?? 0),
    strtolower((string)($source['content_type'] ?? $source['mime'] ?? '')),
    (int)($source['width'] ?? 0),
    (int)($source['height'] ?? 0),
  )));
}

function bratonien_tools_webdav_source_index_metadata(array $source, $shadow_relative)
{
  $path = trim((string)($source['webdav_path'] ?? ''), '/');
  return array(
    'fileid'=>(int)($source['fileid'] ?? 0),
    'root_fileid'=>(int)($source['root_fileid'] ?? 0),
    'webdav_path'=>$path,
    'name'=>basename($path),
    'etag'=>(string)($source['etag'] ?? ''),
    'size'=>(int)($source['size'] ?? 0),
    'content_type'=>(string)($source['content_type'] ?? $source['mime'] ?? ''),
    'width'=>(int)($source['width'] ?? 0),
    'height'=>(int)($source['height'] ?? 0),
    'shadow_relative'=>ltrim((string)$shadow_relative, '/'),
    'source_signature'=>bratonien_tools_webdav_source_index_signature($source),
    'last_seen_at'=>time(),
  );
}

function bratonien_tools_webdav_source_index_is_current(array $entry, $signature)
{
  $signature = (string)$signature;
  return isset($entry['source_signature'], $entry['stage1_signature'], $entry['stage2_signature'])
    && hash_equals((string)$entry['source_signature'], $signature)
    && hash_equals((string)$entry['stage1_signature'], $signature)
    && hash_equals((string)$entry['stage2_signature'], $signature);
}

function bratonien_tools_webdav_source_index_prune(array &$index, array $current_keys)
{
  $keep = array_fill_keys($current_keys, true);
  $removed = 0;
  foreach (array_keys((array)$index['sources']) as $key)
  {
    if (!isset($keep[$key]))
    {
      unset($index['sources'][$key]);
      $removed++;
    }
  }
  return $removed;
}
