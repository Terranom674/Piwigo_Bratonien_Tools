#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

const BRATONIEN_WEBDAV_PREVIEW_VERSION = 2;
const BRATONIEN_WEBDAV_PREVIEW_MAX_EDGE = 4096;
const BRATONIEN_WEBDAV_PREVIEW_JPEG_QUALITY = 88;

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
    CURLOPT_USERAGENT => 'Bratonien-Tools-WebDAV-Precache/0.9.6.0',
  ));
  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false || $errno !== 0) fail_preview('WebDAV-Bild konnte nicht geladen werden: '.$error);
  if ($http < 200 || $http >= 300) fail_preview('WebDAV-Bild antwortete mit HTTP '.$http.'.');
  if ($body === '') fail_preview('WebDAV-Bild ist leer.');
  return (string)$body;
}

function preview_extension_for_entry(array $entry)
{
  $content_type = strtolower(trim((string)($entry['content_type'] ?? '')));
  $path = strtolower((string)($entry['webdav_path'] ?? ''));
  if ($content_type === 'image/png' || preg_match('/\.png$/', $path))
  {
    return 'png';
  }
  return 'jpg';
}

function preview_target_for_entry($cache_dir, $key, array $entry)
{
  return $cache_dir.'/'.$key.'.'.preview_extension_for_entry($entry);
}

function remove_other_preview_format($cache_dir, $key, $keep_target)
{
  foreach (array('jpg', 'png', 'webp') as $ext)
  {
    $candidate = $cache_dir.'/'.$key.'.'.$ext;
    if ($candidate !== $keep_target && is_file($candidate)) @unlink($candidate);
  }
}

function write_preview_imagick($blob, $target, $extension)
{
  $image = new Imagick();
  try
  {
    $image->readImageBlob($blob);
    if (method_exists($image, 'autoOrientImage')) @$image->autoOrientImage();
    $image->setIteratorIndex(0);
    $image->thumbnailImage(BRATONIEN_WEBDAV_PREVIEW_MAX_EDGE, BRATONIEN_WEBDAV_PREVIEW_MAX_EDGE, true, true);
    $image->stripImage();

    if ($extension === 'png')
    {
      $image->setImageFormat('png');
    }
    else
    {
      $image->setImageBackgroundColor('white');
      if (method_exists($image, 'mergeImageLayers'))
      {
        $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
      }
      $image->setImageFormat('jpeg');
      $image->setImageCompression(Imagick::COMPRESSION_JPEG);
      $image->setImageCompressionQuality(BRATONIEN_WEBDAV_PREVIEW_JPEG_QUALITY);
      $image->setInterlaceScheme(Imagick::INTERLACE_PLANE);
    }

    if (!$image->writeImage($target)) fail_preview('Imagick konnte das Preview nicht schreiben.');
  }
  finally
  {
    $image->clear();
    $image->destroy();
  }
}

function write_preview_gd($blob, $target, $extension)
{
  $source = @imagecreatefromstring($blob);
  if (!$source) fail_preview('GD konnte das Bild nicht dekodieren.');

  try
  {
    $width = imagesx($source);
    $height = imagesy($source);
    if ($width < 1 || $height < 1) fail_preview('Bildabmessungen sind ungültig.');

    $scale = min(1, BRATONIEN_WEBDAV_PREVIEW_MAX_EDGE / $width, BRATONIEN_WEBDAV_PREVIEW_MAX_EDGE / $height);
    $target_width = max(1, (int)round($width * $scale));
    $target_height = max(1, (int)round($height * $scale));
    $preview = imagecreatetruecolor($target_width, $target_height);
    if (!$preview) fail_preview('GD konnte die Preview-Arbeitsfläche nicht anlegen.');

    try
    {
      if ($extension === 'png')
      {
        imagealphablending($preview, false);
        imagesavealpha($preview, true);
        $transparent = imagecolorallocatealpha($preview, 0, 0, 0, 127);
        imagefilledrectangle($preview, 0, 0, $target_width, $target_height, $transparent);
      }
      else
      {
        $white = imagecolorallocate($preview, 255, 255, 255);
        imagefilledrectangle($preview, 0, 0, $target_width, $target_height, $white);
      }

      if (!imagecopyresampled($preview, $source, 0, 0, 0, 0, $target_width, $target_height, $width, $height))
      {
        fail_preview('GD konnte das Preview nicht skalieren.');
      }

      $written = $extension === 'png'
        ? imagepng($preview, $target, 6)
        : imagejpeg($preview, $target, BRATONIEN_WEBDAV_PREVIEW_JPEG_QUALITY);
      if (!$written) fail_preview('GD konnte das Preview nicht schreiben.');
    }
    finally
    {
      imagedestroy($preview);
    }
  }
  finally
  {
    imagedestroy($source);
  }
}

function write_preview($blob, $target, $extension)
{
  $dir = dirname($target);
  if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) fail_preview('Preview-Verzeichnis konnte nicht angelegt werden.');

  if (class_exists('Imagick'))
  {
    write_preview_imagick($blob, $target, $extension);
  }
  elseif (function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('imagepng'))
  {
    write_preview_gd($blob, $target, $extension);
  }
  else
  {
    fail_preview('Weder Imagick noch GD ist für die Preview-Erzeugung verfügbar.');
  }

  clearstatcache(true, $target);
  $size = @getimagesize($target);
  if (!is_array($size) || empty($size[0]) || empty($size[1]) || !is_file($target) || filesize($target) < 64)
  {
    @unlink($target);
    fail_preview('Das erzeugte Preview ist ungültig.');
  }
  @chmod($target, 0644);
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
    $target = preview_target_for_entry($cache_dir, $key, $entry);
    $extension = pathinfo($target, PATHINFO_EXTENSION);
    $new_state[$key] = array(
      'path' => $webdav_path,
      'etag' => $etag,
      'format' => $extension,
      'version' => BRATONIEN_WEBDAV_PREVIEW_VERSION,
    );

    $old = $old_state[$key] ?? null;
    if (
      is_file($target)
      && is_array($old)
      && (string)($old['etag'] ?? '') === $etag
      && (string)($old['format'] ?? '') === $extension
      && (int)($old['version'] ?? 0) === BRATONIEN_WEBDAV_PREVIEW_VERSION
    )
    {
      $cached++;
      continue;
    }

    try
    {
      $url = $base_url.'/remote.php/dav/files/'.rawurlencode($user).'/'.quote_webdav_path($webdav_path);
      $blob = fetch_remote_blob($url, $user, $password);
      write_preview($blob, $target, $extension);
      remove_other_preview_format($cache_dir, $key, $target);
      $generated++;
    }
    catch (Throwable $e)
    {
      $errors++;
      fwrite(STDERR, $webdav_path.': '.$e->getMessage()."\n");
    }
  }

  foreach (glob($cache_dir.'/*') ?: array() as $file)
  {
    if (!is_file($file) || basename($file) === 'state.json') continue;
    $name = basename($file);
    if (!preg_match('/^([a-f0-9]{40})\.(jpg|png|webp)$/', $name, $m)) continue;
    if (!isset($new_state[$m[1]])) @unlink($file);
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
