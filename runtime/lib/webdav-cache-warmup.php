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
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/lib/webdav-cache-warmup.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Cache-Warmup/0.9.7.1.8';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
require_once(PHPWG_ROOT_PATH.'include/derivative.inc.php');
if (!defined('BRATONIEN_TOOLS_PATH'))
{
  fwrite(STDERR, "Bratonien Tools ist nicht aktiv.\n");
  exit(1);
}

require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_materialize_runtime.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_warmup_settings.inc.php');

function bratonien_tools_cache_warmup_log($event, array $fields=array())
{
  $parts = array('[BRAT-WARMUP]', $event);
  foreach ($fields as $key=>$value)
  {
    if (is_bool($value)) $value = $value ? '1' : '0';
    elseif ($value === null) $value = 'NULL';
    elseif (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_SLASHES);
    $parts[] = $key.'='.str_replace(array("\r", "\n"), array('\\r', '\\n'), (string)$value);
  }
  fwrite(STDOUT, implode(' ', $parts)."\n");
}

function bratonien_tools_cache_warmup_state_file($connection_id)
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.connection-'.(int)$connection_id.'.json';
}

function bratonien_tools_cache_warmup_status_file($connection_id)
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.status-'.(int)$connection_id.'.json';
}

function bratonien_tools_cache_warmup_priority_file($state_dir)
{
  return rtrim((string)$state_dir, '/').'/webdav-cache-warmup-priority-sync';
}

function bratonien_tools_cache_warmup_priority_pending($priority_file)
{
  return is_string($priority_file) && $priority_file !== '' && is_file($priority_file);
}

function bratonien_tools_cache_warmup_write_json($file, array $payload)
{
  $directory = dirname($file);
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory))
  {
    throw new RuntimeException('Warmup-State-Verzeichnis konnte nicht angelegt werden.');
  }
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('Warmup-State konnte nicht serialisiert werden.');
  $tmp = $file.'.tmp-'.bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, $json."\n", LOCK_EX) === false) throw new RuntimeException('Warmup-State konnte nicht geschrieben werden.');
  @chmod($tmp, 0664);
  if (!@rename($tmp, $file))
  {
    @unlink($tmp);
    throw new RuntimeException('Warmup-State konnte nicht atomar gespeichert werden.');
  }
}

function bratonien_tools_cache_warmup_status($connection_id, $state, $message, array $extra=array())
{
  bratonien_tools_cache_warmup_write_json(
    bratonien_tools_cache_warmup_status_file($connection_id),
    array_merge(array(
      'state'=>$state,
      'message'=>$message,
      'connection_id'=>(int)$connection_id,
      'updated_at'=>time(),
    ), $extra)
  );
}

function bratonien_tools_cache_warmup_load_state($connection_id)
{
  $file = bratonien_tools_cache_warmup_state_file($connection_id);
  if (!is_file($file) || !is_readable($file)) return null;
  $raw = @file_get_contents($file);
  $state = $raw !== false ? json_decode($raw, true) : null;
  return is_array($state) ? $state : null;
}

function bratonien_tools_cache_warmup_save_state($connection_id, array $state)
{
  $state['updated_at'] = time();
  if (!isset($state['albums']) || !is_array($state['albums'])) $state['albums'] = array();
  if (!isset($state['images']) || !is_array($state['images'])) $state['images'] = array();
  bratonien_tools_cache_warmup_write_json(bratonien_tools_cache_warmup_state_file($connection_id), $state);
}

function bratonien_tools_cache_warmup_credentials($connection_id)
{
  $connection = bratonien_tools_nc_connector_connection((int)$connection_id, true);
  if (!$connection || empty($connection['enabled'])) return null;
  $plain = bratonien_tools_nc_connector_decrypt_secret((string)($connection['secret_blob'] ?? ''));
  $secret = json_decode($plain, true);
  if (!is_array($secret)) return null;

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $base_url = rtrim((string)($config['nextcloud_url'] ?? ''), '/');
  $user = trim((string)($secret['nextcloud_user'] ?? ''));
  $password = (string)($secret['nextcloud_password'] ?? '');
  if ($base_url === '' || $user === '' || $password === '') return null;

  return array(
    'connection'=>$connection,
    'config'=>$config,
    'base_url'=>$base_url,
    'user'=>$user,
    'password'=>$password,
  );
}

function bratonien_tools_cache_warmup_absolute_path($path)
{
  $path = (string)$path;
  if ($path === '') return null;
  if (strpos($path, '/') === 0) return $path;
  return PHPWG_ROOT_PATH.ltrim(preg_replace('#^\./#', '', $path), '/');
}

function bratonien_tools_cache_warmup_signature(array $source)
{
  return sha1(implode('|', array(
    (int)($source['connection_id'] ?? 0),
    (int)($source['root_fileid'] ?? 0),
    (int)($source['fileid'] ?? 0),
    trim((string)($source['webdav_path'] ?? ''), '/'),
    (string)($source['etag'] ?? ''),
    (int)($source['size'] ?? 0),
    (int)($source['width'] ?? 0),
    (int)($source['height'] ?? 0),
  )));
}

function bratonien_tools_cache_warmup_scan($connection_id)
{
  $images = array();
  $albums = array();
  $resolved = array();

  $result = pwg_query('SELECT id, path, width, height, rotation, coi FROM '.IMAGES_TABLE.' ORDER BY id');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $image_id = (int)$row['id'];
    $source = bratonien_tools_webdav_materialize_source_info($image_id);
    if (!$source || (int)$source['connection_id'] !== (int)$connection_id) continue;

    $logical = bratonien_tools_cache_warmup_absolute_path($row['path'] ?? '');
    $real = $logical ? realpath($logical) : false;
    if ($real === false || !is_file($real))
    {
      throw new RuntimeException('Bild #'.$image_id.': bestehender Piwigo-Pfad kann nicht aufgelöst werden.');
    }
    $normalized = str_replace('\\', '/', $real);
    $expected = '/nc-webdav-source/connection-'.(int)$source['connection_id'].'/root-'.(int)$source['root_fileid'].'/';
    if (strpos($normalized, $expected) === false)
    {
      throw new RuntimeException('Bild #'.$image_id.': bestehender Piwigo-Pfad zeigt nicht auf die erwartete Connector-Wurzel.');
    }
    if (trim((string)($source['webdav_path'] ?? ''), '/') === '')
    {
      throw new RuntimeException('Bild #'.$image_id.': WebDAV-Mapping enthält keinen Pfad.');
    }

    if (isset($resolved[$normalized]) && $resolved[$normalized] !== $image_id)
    {
      throw new RuntimeException('Sicherheitsabbruch: Bild #'.$resolved[$normalized].' und Bild #'.$image_id.' zeigen auf dieselbe physische Connector-Quelle.');
    }
    $resolved[$normalized] = $image_id;

    $row['id'] = $image_id;
    $images[$image_id] = array(
      'row'=>$row,
      'source'=>$source,
      'logical'=>$logical,
      'resolved'=>$real,
      'signature'=>bratonien_tools_cache_warmup_signature($source),
      'albums'=>array(),
    );
  }

  if ($images)
  {
    foreach (array_chunk(array_keys($images), 500) as $chunk)
    {
      $result = pwg_query('SELECT image_id, category_id FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id IN ('.implode(',', array_map('intval', $chunk)).')');
      while ($rel = pwg_db_fetch_assoc($result))
      {
        $image_id = (int)$rel['image_id'];
        $category_id = (int)$rel['category_id'];
        if (!isset($images[$image_id]) || $category_id < 1) continue;
        $images[$image_id]['albums'][$category_id] = true;
        $albums[$category_id] = true;
      }
    }
  }

  foreach ($images as &$image) $image['albums'] = array_map('intval', array_keys($image['albums']));
  unset($image);
  return array('images'=>$images, 'albums'=>array_map('intval', array_keys($albums)));
}

function bratonien_tools_cache_warmup_baseline($connection_id, array $scan)
{
  $state = array('albums'=>array(), 'images'=>array(), 'last_periodic_at'=>time());
  foreach ($scan['albums'] as $album_id) $state['albums'][(string)$album_id] = array('seen_at'=>time());
  foreach ($scan['images'] as $image_id=>$image)
  {
    $state['images'][(string)$image_id] = array(
      'stage1_signature'=>$image['signature'],
      'stage2_signature'=>$image['signature'],
      'baseline'=>true,
    );
  }
  bratonien_tools_cache_warmup_save_state($connection_id, $state);
  return $state;
}

function bratonien_tools_cache_warmup_select(array $scan, array $state, $mode)
{
  $known_albums = array_fill_keys(array_map('intval', array_keys((array)($state['albums'] ?? array()))), true);
  $new_albums = array();
  foreach ($scan['albums'] as $album_id)
  {
    if (!isset($known_albums[(int)$album_id])) $new_albums[(int)$album_id] = true;
  }

  $selected = array();
  foreach ($scan['images'] as $image_id=>$image)
  {
    $saved = isset($state['images'][(string)$image_id]) && is_array($state['images'][(string)$image_id])
      ? $state['images'][(string)$image_id]
      : array();
    $stage1_done = isset($saved['stage1_signature']) && hash_equals((string)$saved['stage1_signature'], $image['signature']);
    $stage2_done = isset($saved['stage2_signature']) && hash_equals((string)$saved['stage2_signature'], $image['signature']);

    $in_new_album = false;
    foreach ($image['albums'] as $album_id)
    {
      if (isset($new_albums[(int)$album_id])) { $in_new_album = true; break; }
    }

    if ($mode === 'sync')
    {
      if ($in_new_album && (!$stage1_done || !$stage2_done)) $selected[$image_id] = $image;
    }
    else
    {
      if (!$stage1_done || !$stage2_done) $selected[$image_id] = $image;
    }
  }

  return array('images'=>$selected, 'new_albums'=>array_map('intval', array_keys($new_albums)));
}

function bratonien_tools_cache_warmup_variant_rows(array $image, $stage)
{
  $src = new SrcImage($image['row']);
  $variants = array();
  foreach (ImageStdParams::get_defined_type_map() as $type=>$params)
  {
    $derivative = new DerivativeImage($params, $src);
    if ($derivative->same_as_source()) continue;
    $size = $derivative->get_size();
    $max_edge = max((int)($size[0] ?? 0), (int)($size[1] ?? 0));
    $priority = $max_edge > 0 && $max_edge <= 1920;
    if (($stage === 1 && $priority) || ($stage === 2 && !$priority))
    {
      $variants[] = array('name'=>'standard:'.$type, 'target'=>$derivative->get_path());
    }
  }
  foreach (ImageStdParams::$custom as $key=>$last_used)
  {
    $params = bratonien_tools_webdav_materialize_custom_params($key);
    if (!$params) continue;
    $derivative = new DerivativeImage($params, $src);
    if ($derivative->same_as_source()) continue;
    $size = $derivative->get_size();
    $max_edge = max((int)($size[0] ?? 0), (int)($size[1] ?? 0));
    $priority = $max_edge > 0 && $max_edge <= 1920;
    if (($stage === 1 && $priority) || ($stage === 2 && !$priority))
    {
      $variants[] = array('name'=>'custom:'.$key, 'target'=>$derivative->get_path());
    }
  }
  return $variants;
}

function bratonien_tools_cache_warmup_i_request($target)
{
  $root = PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR;
  if (strpos($target, $root) !== 0) return null;
  $relative = ltrim(substr($target, strlen($root)), '/');
  if ($relative === '') return null;
  return '/i.php?/'.implode('/', array_map('rawurlencode', explode('/', $relative)));
}

function bratonien_tools_cache_warmup_call_piwigo($request, &$detail=null)
{
  $detail = '';
  $runner = BRATONIEN_TOOLS_PATH.'runtime/lib/piwigo-derivative-call.php';
  if (!is_file($runner)) { $detail = 'Piwigo-Derivataufruf fehlt.'; return false; }
  if (!function_exists('exec')) { $detail = 'PHP exec() ist deaktiviert.'; return false; }
  $output = array();
  $exit = 1;
  $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($runner).' --request='.escapeshellarg($request).' 2>&1';
  @exec($command, $output, $exit);
  if ($exit !== 0)
  {
    $detail = 'Piwigo-Aufruf Exit '.$exit;
    if ($output) $detail .= ': '.implode(' ', array_slice($output, -3));
    return false;
  }
  return true;
}

function bratonien_tools_cache_warmup_quote_path($path)
{
  $parts = array_values(array_filter(explode('/', trim((string)$path, '/')), 'strlen'));
  return implode('/', array_map('rawurlencode', $parts));
}

function bratonien_tools_cache_warmup_download(array $source, array $credentials, $destination, &$detail=null)
{
  $detail = '';
  $webdav_path = trim((string)($source['webdav_path'] ?? ''), '/');
  if ($webdav_path === '') { $detail = 'WebDAV-Pfad fehlt.'; return false; }
  $fp = @fopen($destination, 'xb');
  if (!$fp) { $detail = 'Temporäre Quelldatei konnte nicht angelegt werden.'; return false; }
  $url = $credentials['base_url'].'/remote.php/dav/files/'.rawurlencode($credentials['user']).'/'.bratonien_tools_cache_warmup_quote_path($webdav_path);
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_CONNECTTIMEOUT=>10,
    CURLOPT_TIMEOUT=>300,
    CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
    CURLOPT_USERPWD=>$credentials['user'].':'.$credentials['password'],
    CURLOPT_RETURNTRANSFER=>false,
    CURLOPT_FAILONERROR=>false,
    CURLOPT_FILE=>$fp,
    CURLOPT_USERAGENT=>'Bratonien-WebDAV-Cache-Warmup/0.9.7.1.8',
  ));
  $ok = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  fclose($fp);
  if ($ok === false || $errno !== 0 || $http < 200 || $http >= 300)
  {
    @unlink($destination);
    $detail = 'Nextcloud-Download fehlgeschlagen (HTTP '.$http.', cURL '.$errno.($error !== '' ? ': '.$error : '').').';
    return false;
  }
  if (!is_file($destination) || filesize($destination) < 1 || @getimagesize($destination) === false)
  {
    @unlink($destination);
    $detail = 'Geladene Datei ist kein lesbares vollständiges Bild.';
    return false;
  }
  return true;
}

function bratonien_tools_cache_warmup_sync_guard(array $credentials, &$handle=null, &$detail=null)
{
  $handle = null;
  $detail = '';
  $state_dir = rtrim((string)($credentials['config']['state_dir'] ?? ''), '/');
  if ($state_dir === '') { $detail = 'Connector-State-Verzeichnis fehlt.'; return false; }
  $handle = @fopen($state_dir.'/webdav-sync.lock', 'c');
  if (!$handle) { $detail = 'Connector-Sync-Lock konnte nicht geöffnet werden.'; return false; }
  if (!@flock($handle, LOCK_SH | LOCK_NB))
  {
    fclose($handle);
    $handle = null;
    $detail = 'Connector-Synchronisierung läuft.';
    return false;
  }
  return true;
}

function bratonien_tools_cache_warmup_image_lock($image_id, &$handle=null)
{
  $dir = PHPWG_ROOT_PATH.'upload/bratonien-webdav-materialize';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
  $handle = @fopen($dir.'/image-'.(int)$image_id.'.lock', 'c');
  if (!$handle) return false;
  return @flock($handle, LOCK_EX);
}

function bratonien_tools_cache_warmup_unlock(&$handle)
{
  if (is_resource($handle))
  {
    @flock($handle, LOCK_UN);
    fclose($handle);
  }
  $handle = null;
}

function bratonien_tools_cache_warmup_restore($source_path, $backup, $staging, $original_size, $original_hash, &$detail=null)
{
  $detail = '';
  @unlink($staging);
  if (is_file($source_path) || is_link($source_path))
  {
    if (!@unlink($source_path)) { $detail = 'Temporäre Quelle konnte nicht entfernt werden.'; return false; }
  }
  if (!is_file($backup)) { $detail = 'Placeholder-Backup fehlt.'; return false; }
  if (!@rename($backup, $source_path)) { $detail = 'Placeholder konnte nicht restauriert werden.'; return false; }
  clearstatcache(true, $source_path);
  if (!is_file($source_path)) { $detail = 'Restaurierter Placeholder fehlt.'; return false; }
  if ((int)filesize($source_path) !== (int)$original_size) { $detail = 'Restaurierter Placeholder hat eine unerwartete Größe.'; return false; }
  $hash = @sha1_file($source_path);
  if (!is_string($hash) || !hash_equals($original_hash, $hash)) { $detail = 'Restaurierter Placeholder stimmt nicht mit dem Originalzustand überein.'; return false; }
  return true;
}

function bratonien_tools_cache_warmup_process(array $image, $temp_file, array $credentials, $stage, $force=false)
{
  $image_id = (int)$image['row']['id'];
  $variants = bratonien_tools_cache_warmup_variant_rows($image, $stage);
  $pending = array();
  foreach ($variants as $variant)
  {
    if (!$force && is_file($variant['target']) && is_readable($variant['target'])) continue;
    $request = bratonien_tools_cache_warmup_i_request($variant['target']);
    if ($request !== null) $pending[] = array('name'=>$variant['name'], 'target'=>$variant['target'], 'request'=>$request);
  }
  if (!$pending) return array('ok'=>true, 'generated'=>0, 'message'=>'Bereits im Piwigo-Cache vorhanden.');

  $image_lock = null;
  if (!bratonien_tools_cache_warmup_image_lock($image_id, $image_lock))
  {
    return array('ok'=>false, 'defer'=>true, 'message'=>'Bild-Lock konnte nicht gesetzt werden.');
  }
  $sync_lock = null;
  $detail = '';
  if (!bratonien_tools_cache_warmup_sync_guard($credentials, $sync_lock, $detail))
  {
    bratonien_tools_cache_warmup_unlock($image_lock);
    return array('ok'=>false, 'defer'=>true, 'message'=>$detail);
  }

  $fresh = bratonien_tools_webdav_materialize_source_info($image_id);
  if (!$fresh || (int)$fresh['connection_id'] !== (int)$image['source']['connection_id'] || bratonien_tools_cache_warmup_signature($fresh) !== $image['signature'])
  {
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($image_lock);
    return array('ok'=>false, 'defer'=>true, 'message'=>'WebDAV-Zuordnung hat sich seit dem Batch-Download geändert.');
  }

  $source_path = realpath($image['logical']);
  if ($source_path === false || $source_path !== $image['resolved'] || !is_file($source_path))
  {
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($image_lock);
    return array('ok'=>false, 'fatal'=>true, 'message'=>'Piwigo-Quellpfad hat sich während des Warmups geändert.');
  }
  if (!is_file($temp_file) || !is_readable($temp_file) || @getimagesize($temp_file) === false)
  {
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($image_lock);
    return array('ok'=>false, 'message'=>'Batch-Quelldatei ist nicht vollständig lesbar.');
  }

  $placeholder_size = filesize($source_path);
  $placeholder_hash = @sha1_file($source_path);
  if (!is_string($placeholder_hash))
  {
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($image_lock);
    return array('ok'=>false, 'fatal'=>true, 'message'=>'Placeholder konnte vor dem Swap nicht verifiziert werden.');
  }

  $request_id = substr(bin2hex(random_bytes(8)), 0, 8);
  $backup = $source_path.'.bratonien-placeholder-'.$request_id;
  $staging = $source_path.'.bratonien-source-'.$request_id;
  if (file_exists($backup) || file_exists($staging))
  {
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($image_lock);
    return array('ok'=>false, 'fatal'=>true, 'message'=>'Unerwartete Swap-Datei ist bereits vorhanden.');
  }
  if (!@copy($temp_file, $staging) || @getimagesize($staging) === false)
  {
    @unlink($staging);
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($image_lock);
    return array('ok'=>false, 'message'=>'Batch-Quelle konnte nicht sicher neben dem Placeholder bereitgestellt werden.');
  }

  $swapped = false;
  $generated = 0;
  try
  {
    if (!@rename($source_path, $backup)) throw new RuntimeException('Placeholder konnte nicht atomar gesichert werden.');
    if (!@rename($staging, $source_path))
    {
      @rename($backup, $source_path);
      throw new RuntimeException('Temporäre Quelle konnte nicht atomar eingesetzt werden.');
    }
    $swapped = true;
    clearstatcache(true, $source_path);
    if (@getimagesize($source_path) === false) throw new RuntimeException('Piwigo-Quellpfad enthält nach dem Swap kein lesbares Bild.');

    foreach ($pending as $variant)
    {
      $call_detail = '';
      if (!bratonien_tools_cache_warmup_call_piwigo($variant['request'], $call_detail))
      {
        throw new RuntimeException($variant['name'].': '.$call_detail);
      }
      clearstatcache(true, $variant['target']);
      if (!is_file($variant['target']) || !is_readable($variant['target']))
      {
        throw new RuntimeException($variant['name'].': Piwigo hat kein Derivat im eigenen Cache erzeugt.');
      }
      $generated++;
    }
  }
  catch (Throwable $e)
  {
    $restore_detail = '';
    $restored = $swapped
      ? bratonien_tools_cache_warmup_restore($source_path, $backup, $staging, $placeholder_size, $placeholder_hash, $restore_detail)
      : true;
    if (!$swapped) @unlink($staging);
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($image_lock);
    if (!$restored) return array('ok'=>false, 'fatal'=>true, 'message'=>$e->getMessage().' RESTORE FEHLGESCHLAGEN: '.$restore_detail);
    return array('ok'=>false, 'message'=>$e->getMessage());
  }

  $restore_detail = '';
  $restored = bratonien_tools_cache_warmup_restore($source_path, $backup, $staging, $placeholder_size, $placeholder_hash, $restore_detail);
  bratonien_tools_cache_warmup_unlock($sync_lock);
  bratonien_tools_cache_warmup_unlock($image_lock);
  if (!$restored) return array('ok'=>false, 'fatal'=>true, 'message'=>'RESTORE FEHLGESCHLAGEN: '.$restore_detail);
  return array('ok'=>true, 'generated'=>$generated, 'message'=>'Piwigo hat die angeforderten Derivate erzeugt.');
}

function bratonien_tools_cache_warmup_stage_pending(array $selected, array $state, $stage)
{
  $pending = array();
  $key = $stage === 1 ? 'stage1_signature' : 'stage2_signature';
  foreach ($selected as $image_id=>$image)
  {
    $saved = isset($state['images'][(string)$image_id]) && is_array($state['images'][(string)$image_id]) ? $state['images'][(string)$image_id] : array();
    if (!isset($saved[$key]) || !hash_equals((string)$saved[$key], $image['signature'])) $pending[$image_id] = $image;
  }
  return $pending;
}

function bratonien_tools_cache_warmup_run_stage($connection_id, $stage, array $selected, array &$state, array $credentials, $batch_size, $priority_file='')
{
  $pending = bratonien_tools_cache_warmup_stage_pending($selected, $state, $stage);
  if (!$pending) return array('ok'=>true, 'success'=>array(), 'failed'=>0, 'preempted'=>false);

  if ($stage === 2 && bratonien_tools_cache_warmup_priority_pending($priority_file))
  {
    bratonien_tools_cache_warmup_log('stage2_preempted', array('connection_id'=>$connection_id, 'point'=>'before_first_batch'));
    return array('ok'=>true, 'success'=>array(), 'failed'=>0, 'preempted'=>true);
  }

  $temp_root = PHPWG_ROOT_PATH.'upload/bratonien-webdav-warmup';
  if (!is_dir($temp_root) && !@mkdir($temp_root, 0775, true) && !is_dir($temp_root)) throw new RuntimeException('Warmup-Temp-Verzeichnis konnte nicht angelegt werden.');
  $success = array();
  $failed = 0;
  $batch_number = 0;

  foreach (array_chunk(array_keys($pending), max(1, (int)$batch_size)) as $ids)
  {
    $batch_number++;
    $dir = $temp_root.'/connection-'.(int)$connection_id.'-stage'.$stage.'-'.date('YmdHis').'-'.bin2hex(random_bytes(4));
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Warmup-Batch-Verzeichnis konnte nicht angelegt werden.');

    $downloads = array();
    foreach ($ids as $image_id)
    {
      $file = $dir.'/image-'.(int)$image_id.'.img';
      $detail = '';
      if (bratonien_tools_cache_warmup_download($pending[$image_id]['source'], $credentials, $file, $detail))
      {
        $downloads[$image_id] = $file;
      }
      else
      {
        $failed++;
        bratonien_tools_cache_warmup_log('download_failed', array('stage'=>$stage, 'image_id'=>$image_id, 'detail'=>$detail));
      }
    }

    bratonien_tools_cache_warmup_status($connection_id, 'running', 'Warmup Stufe '.$stage.' läuft.', array(
      'stage'=>$stage,
      'batch'=>$batch_number,
      'batch_requested'=>count($ids),
      'batch_downloaded'=>count($downloads),
    ));

    foreach ($downloads as $image_id=>$file)
    {
      $saved = isset($state['images'][(string)$image_id]) && is_array($state['images'][(string)$image_id]) ? $state['images'][(string)$image_id] : array();
      $force = !empty($saved['baseline']) || (
        isset($saved['stage1_signature']) && !hash_equals((string)$saved['stage1_signature'], $pending[$image_id]['signature'])
      );
      if ($stage === 2 && isset($saved['stage2_signature']) && !hash_equals((string)$saved['stage2_signature'], $pending[$image_id]['signature'])) $force = true;

      $result = bratonien_tools_cache_warmup_process($pending[$image_id], $file, $credentials, $stage, $force);
      bratonien_tools_cache_warmup_log('image', array(
        'stage'=>$stage,
        'image_id'=>$image_id,
        'ok'=>!empty($result['ok']),
        'generated'=>(int)($result['generated'] ?? 0),
        'message'=>(string)($result['message'] ?? ''),
      ));
      if (!empty($result['fatal']))
      {
        foreach ($downloads as $cleanup) @unlink($cleanup);
        @rmdir($dir);
        throw new RuntimeException('Sicherheitsabbruch bei Bild #'.$image_id.': '.(string)$result['message']);
      }
      if (empty($result['ok']))
      {
        $failed++;
        continue;
      }

      if (!isset($state['images'][(string)$image_id]) || !is_array($state['images'][(string)$image_id])) $state['images'][(string)$image_id] = array();
      $state['images'][(string)$image_id][$stage === 1 ? 'stage1_signature' : 'stage2_signature'] = $pending[$image_id]['signature'];
      unset($state['images'][(string)$image_id]['baseline']);
      bratonien_tools_cache_warmup_save_state($connection_id, $state);
      $success[$image_id] = true;
    }

    foreach ($downloads as $file) @unlink($file);
    @rmdir($dir);

    if ($stage === 2 && bratonien_tools_cache_warmup_priority_pending($priority_file))
    {
      bratonien_tools_cache_warmup_log('stage2_preempted', array('connection_id'=>$connection_id, 'point'=>'after_batch', 'batch'=>$batch_number));
      return array('ok'=>$failed === 0, 'success'=>$success, 'failed'=>$failed, 'preempted'=>true);
    }
  }
  return array('ok'=>$failed === 0, 'success'=>$success, 'failed'=>$failed, 'preempted'=>false);
}

function bratonien_tools_cache_warmup_run($connection_id, $mode)
{
  $settings = bratonien_tools_get_webdav_warmup_settings();
  if ($mode !== 'manual' && empty($settings['enabled']))
  {
    bratonien_tools_cache_warmup_log('disabled', array('connection_id'=>$connection_id, 'mode'=>$mode));
    return 0;
  }

  $credentials = bratonien_tools_cache_warmup_credentials($connection_id);
  if (!$credentials) throw new RuntimeException('WebDAV-Verbindung oder Zugangsdaten konnten nicht geladen werden.');
  $state_dir = rtrim((string)($credentials['config']['state_dir'] ?? ''), '/');
  if ($state_dir === '') throw new RuntimeException('Connector-State-Verzeichnis fehlt.');
  if (!is_dir($state_dir) && !@mkdir($state_dir, 0750, true) && !is_dir($state_dir)) throw new RuntimeException('Connector-State-Verzeichnis konnte nicht angelegt werden.');
  $priority_file = bratonien_tools_cache_warmup_priority_file($state_dir);

  $process_lock = @fopen($state_dir.'/webdav-cache-warmup.lock', 'c');
  if (!$process_lock || !@flock($process_lock, LOCK_EX | LOCK_NB))
  {
    if (is_resource($process_lock)) fclose($process_lock);
    bratonien_tools_cache_warmup_log('already_running', array('connection_id'=>$connection_id));
    return 0;
  }

  try
  {
    // Nur der Sync-Worker, der den Prozess-Lock tatsächlich erhalten hat,
    // konsumiert seine eigene Prioritätsmarke. Ein zweiter Sync-Aufruf während
    // eines laufenden Workers lässt die Marke stehen, damit der aktive Lauf
    // Stufe 2 sicher zwischen zwei vollständigen Batches unterbrechen kann.
    if ($mode === 'sync' && is_file($priority_file)) @unlink($priority_file);

    $scan = bratonien_tools_cache_warmup_scan($connection_id);
    $state = bratonien_tools_cache_warmup_load_state($connection_id);
    if ($state === null)
    {
      bratonien_tools_cache_warmup_baseline($connection_id, $scan);
      bratonien_tools_cache_warmup_status($connection_id, 'baseline', 'Bestehender produktiver Bestand wurde nur als Ausgangszustand erfasst.', array(
        'images'=>count($scan['images']),
        'albums'=>count($scan['albums']),
      ));
      bratonien_tools_cache_warmup_log('baseline', array('connection_id'=>$connection_id, 'images'=>count($scan['images']), 'albums'=>count($scan['albums'])));
      return 0;
    }

    if ($mode === 'periodic')
    {
      $last = (int)($state['last_periodic_at'] ?? 0);
      $interval = max(1, (int)$settings['periodic_hours']) * 3600;
      if ($last > 0 && time() - $last < $interval)
      {
        bratonien_tools_cache_warmup_log('periodic_not_due', array('connection_id'=>$connection_id, 'remaining'=>$interval-(time()-$last)));
        return 0;
      }
    }

    $selection = bratonien_tools_cache_warmup_select($scan, $state, $mode);
    $selected = $selection['images'];
    bratonien_tools_cache_warmup_status($connection_id, 'scan', 'Warmup-Eingangsprüfung abgeschlossen.', array(
      'mode'=>$mode,
      'images'=>count($scan['images']),
      'selected'=>count($selected),
      'new_albums'=>count($selection['new_albums']),
    ));

    if (!$selected)
    {
      foreach ($scan['albums'] as $album_id) $state['albums'][(string)$album_id] = array('seen_at'=>time());
      if ($mode === 'periodic' || $mode === 'manual') $state['last_periodic_at'] = time();
      bratonien_tools_cache_warmup_save_state($connection_id, $state);
      bratonien_tools_cache_warmup_status($connection_id, 'complete', 'Keine neuen oder geänderten Bilder für den Warmup gefunden.', array('mode'=>$mode));
      return 0;
    }

    $stage1 = bratonien_tools_cache_warmup_run_stage($connection_id, 1, $selected, $state, $credentials, $settings['batch_size'], $priority_file);
    $stage2_candidates = array();
    foreach ($selected as $image_id=>$image)
    {
      $saved = isset($state['images'][(string)$image_id]) && is_array($state['images'][(string)$image_id]) ? $state['images'][(string)$image_id] : array();
      if (isset($saved['stage1_signature']) && hash_equals((string)$saved['stage1_signature'], $image['signature'])) $stage2_candidates[$image_id] = $image;
    }
    $stage2 = bratonien_tools_cache_warmup_run_stage($connection_id, 2, $stage2_candidates, $state, $credentials, $settings['batch_size'], $priority_file);

    if (!empty($stage2['preempted']))
    {
      bratonien_tools_cache_warmup_status($connection_id, 'preempted', 'Warmup Stufe 2 wurde nach einem vollständigen Batch für neue Sync-Priorität freigegeben.', array(
        'mode'=>$mode,
        'stage1_failed'=>(int)$stage1['failed'],
        'stage2_failed'=>(int)$stage2['failed'],
      ));
      return ((int)$stage1['failed'] + (int)$stage2['failed']) > 0 ? 2 : 0;
    }

    foreach ($scan['albums'] as $album_id) $state['albums'][(string)$album_id] = array('seen_at'=>time());
    if ($mode === 'periodic' || $mode === 'manual') $state['last_periodic_at'] = time();
    bratonien_tools_cache_warmup_save_state($connection_id, $state);

    $failed = (int)$stage1['failed'] + (int)$stage2['failed'];
    bratonien_tools_cache_warmup_status($connection_id, $failed ? 'error' : 'complete', $failed ? 'Warmup mit einzelnen Fehlern beendet.' : 'Warmup vollständig beendet.', array(
      'mode'=>$mode,
      'stage1_failed'=>(int)$stage1['failed'],
      'stage2_failed'=>(int)$stage2['failed'],
    ));
    return $failed ? 2 : 0;
  }
  finally
  {
    @flock($process_lock, LOCK_UN);
    fclose($process_lock);
  }
}

$connection_id = 0;
$mode = 'periodic';
foreach ($argv as $arg)
{
  if (preg_match('/^--connection-id=(\d+)$/', $arg, $m)) $connection_id = (int)$m[1];
  elseif (preg_match('/^--mode=(sync|periodic|manual)$/', $arg, $m)) $mode = $m[1];
}
if ($connection_id < 1)
{
  fwrite(STDERR, "--connection-id fehlt oder ist ungültig.\n");
  exit(1);
}

try
{
  exit(bratonien_tools_cache_warmup_run($connection_id, $mode));
}
catch (Throwable $e)
{
  try { bratonien_tools_cache_warmup_status($connection_id, 'fatal', $e->getMessage()); } catch (Throwable $ignored) {}
  fwrite(STDERR, '[BRAT-WARMUP] fatal '.$e->getMessage()."\n");
  exit(1);
}
