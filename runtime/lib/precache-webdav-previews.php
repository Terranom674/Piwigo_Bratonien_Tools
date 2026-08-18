#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

function fail_preview($message)
{
  throw new RuntimeException($message);
}

function quote_webdav_path($path)
{
  $parts = array_values(array_filter(explode('/', trim((string)$path, '/')), 'strlen'));
  return implode('/', array_map('rawurlencode', $parts));
}

function fetch_remote_blob($url, $user, $password)
{
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $user.':'.$password,
    CURLOPT_FAILONERROR => false,
    CURLOPT_USERAGENT => 'Bratonien-Tools-WebDAV-Precache/0.9.5.19',
  ));
  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($body === false || $errno !== 0) fail_preview('WebDAV-Bild konnte nicht geladen werden: '.$error);
  if ($http < 200 || $http >= 300) fail_preview('WebDAV-Bild antwortete mit HTTP '.$http.'.');
  return (string)$body;
}

function write_preview($blob, $target)
{
  $dir = dirname($target);
  if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) fail_preview('Preview-Verzeichnis konnte nicht angelegt werden.');

  if (class_exists('Imagick'))
  {
    $image = new Imagick();
    $image->readImageBlob($blob);
    if (method_exists($image, 'autoOrientImage')) @$image->autoOrientImage();
    $image->setIteratorIndex(0);
    $image->thumbnailImage(1600, 1600, true, true);
    $image->setImageFormat('webp');
    $image->setImageCompressionQuality(85);
    if (!$image->writeImage($target)) fail_preview('Imagick konnte das Preview nicht schreiben.');
    $image->clear();
    $image->destroy();
    @chmod($target, 0644);
    return;
  }

  if (function_exists('imagecreatefromstring') && function_exists('imagewebp'))
  {
    $source = @imagecreatefromstring($blob);
    if (!$source) fail_preview('GD konnte das Bild nicht dekodieren.');
    $width = imagesx($source);
    $height = imagesy($source);
    if ($width < 1 || $height < 1)
    {
      imagedestroy($source);
      fail_preview('Bildabmessungen sind ungültig.');
    }
    $scale = min(1, 1600 / $width, 1600 / $height);
    $target_width = max(1, (int)round($width * $scale));
    $target_height = max(1, (int)round($height * $scale));
    $preview = imagecreatetruecolor($target_width, $target_height);
    imagealphablending($preview, false);
    imagesavealpha($preview, true);
    $transparent = imagecolorallocatealpha($preview, 0, 0, 0, 127);
    imagefilledrectangle($preview, 0, 0, $target_width, $target_height, $transparent);
    imagecopyresampled($preview, $source, 0, 0, 0, 0, $target_width, $target_height, $width, $height);
    if (!imagewebp($preview, $target, 85))
    {
      imagedestroy($preview);
      imagedestroy($source);
      fail_preview('GD konnte das Preview nicht schreiben.');
    }
    imagedestroy($preview);
    imagedestroy($source);
    @chmod($target, 0644);
    return;
  }

  fail_preview('Weder Imagick noch GD/WebP ist für die Preview-Erzeugung verfügbar.');
}

try
{
  $options = getopt('', array('mapping:', 'base-url:', 'user:', 'password-file:', 'cache-dir:'));
  $mapping_file = (string)($options['mapping'] ?? '');
  $base_url = rtrim((string)($options['base-url'] ?? ''), '/');
  $user = trim((string)($options['user'] ?? ''));
  $password_file = (string)($options['password-file'] ?? '');
  $cache_dir = rtrim((string)($options['cache-dir'] ?? ''), '/');

  if ($mapping_file === '' || !is_readable($mapping_file)) fail_preview('WebDAV-Mapping ist nicht lesbar.');
  if ($base_url === '' || $user === '' || $password_file === '' || !is_readable($password_file) || $cache_dir === '') fail_preview('Preview-Parameter sind unvollständig.');
  if (!function_exists('curl_init')) fail_preview('PHP-cURL ist nicht verfügbar.');

  $password = rtrim((string)file_get_contents($password_file), "\r\n");
  if ($password === '') fail_preview('Nextcloud-Passwort fehlt.');

  $mapping = json_decode((string)file_get_contents($mapping_file), true);
  if (!is_array($mapping) || !isset($mapping['files']) || !is_array($mapping['files'])) fail_preview('WebDAV-Mapping ist ungültig.');

  if (!is_dir($cache_dir) && !mkdir($cache_dir, 0755, true) && !is_dir($cache_dir)) fail_preview('Preview-Cache konnte nicht angelegt werden.');
  @chmod($cache_dir, 0755);
  $state_file = $cache_dir.'/state.json';
  $old_state = array();
  if (is_readable($state_file))
  {
    $decoded = json_decode((string)file_get_contents($state_file), true);
    if (is_array($decoded)) $old_state = $decoded;
  }

  $new_state = array();
  $generated = 0;
  $cached = 0;
  $errors = 0;

  foreach ($mapping['files'] as $entry)
  {
    if (!is_array($entry) || (string)($entry['kind'] ?? '') !== 'file') continue;
    $webdav_path = trim((string)($entry['webdav_path'] ?? ''), '/');
    if ($webdav_path === '') continue;
    $etag = (string)($entry['etag'] ?? '');
    $key = sha1($webdav_path);
    $target = $cache_dir.'/'.$key.'.webp';
    $new_state[$key] = array('path'=>$webdav_path, 'etag'=>$etag);

    if (is_file($target) && isset($old_state[$key]) && (string)($old_state[$key]['etag'] ?? '') === $etag)
    {
      $cached++;
      continue;
    }

    try
    {
      $url = $base_url.'/remote.php/dav/files/'.rawurlencode($user).'/'.quote_webdav_path($webdav_path);
      $blob = fetch_remote_blob($url, $user, $password);
      write_preview($blob, $target);
      $generated++;
    }
    catch (Throwable $e)
    {
      $errors++;
      fwrite(STDERR, $webdav_path.': '.$e->getMessage()."\n");
    }
  }

  foreach (glob($cache_dir.'/*.webp') ?: array() as $file)
  {
    $key = basename($file, '.webp');
    if (!isset($new_state[$key])) @unlink($file);
  }

  file_put_contents($state_file, json_encode($new_state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n", LOCK_EX);
  @chmod($state_file, 0644);

  echo 'WebDAV-Previews: erzeugt='.$generated.' vorhanden='.$cached.' fehler='.$errors."\n";
  exit($errors > 0 ? 1 : 0);
}
catch (Throwable $e)
{
  fwrite(STDERR, 'WebDAV-Preview-Cache: '.$e->getMessage()."\n");
  exit(1);
}
