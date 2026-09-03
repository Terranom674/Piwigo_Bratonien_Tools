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
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/runtime/lib/webdav-warmup.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Warmup/0.9.7.1.8';
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

function bratonien_tools_warmup_log($event, array $fields=array())
{
  $parts = array('[BRAT-WARMUP]', $event);
  foreach ($fields as $key => $value)
  {
    if (is_bool($value)) $value = $value ? '1' : '0';
    elseif ($value === null) $value = 'NULL';
    elseif (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_SLASHES);
    $parts[] = $key.'='.str_replace(array("\r", "\n"), array('\\r', '\\n'), (string)$value);
  }
  fwrite(STDOUT, implode(' ', $parts)."\n");
}

function bratonien_tools_warmup_absolute_source_path(array $image_row)
{
  $path = (string)($image_row['path'] ?? '');
  if ($path === '') return null;
  if (strpos($path, '/') === 0) return $path;
  return PHPWG_ROOT_PATH.ltrim(preg_replace('#^\./#', '', $path), '/');
}

function bratonien_tools_warmup_connection_credentials($connection_id)
{
  $connection = bratonien_tools_nc_connector_connection((int)$connection_id, true);
  if (!$connection || empty($connection['enabled'])) return null;

  $plain = bratonien_tools_nc_connector_decrypt_secret((string)($connection['secret_blob'] ?? ''));
  $credentials = json_decode($plain, true);
  if (!is_array($credentials)) return null;

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $base_url = rtrim((string)($config['nextcloud_url'] ?? ''), '/');
  $user = trim((string)($credentials['nextcloud_user'] ?? ''));
  $password = (string)($credentials['nextcloud_password'] ?? '');
  if ($base_url === '' || $user === '' || $password === '') return null;

  return array(
    'connection'=>$connection,
    'config'=>$config,
    'base_url'=>$base_url,
    'user'=>$user,
    'password'=>$password,
  );
}

function bratonien_tools_warmup_quote_path($path)
{
  $parts = array_values(array_filter(explode('/', trim((string)$path, '/')), 'strlen'));
  return implode('/', array_map('rawurlencode', $parts));
}

function bratonien_tools_warmup_download(array $source, array $credentials, $destination, &$detail=null)
{
  $detail = '';
  $webdav_path = trim((string)($source['webdav_path'] ?? ''), '/');
  if ($webdav_path === '')
  {
    $detail = 'WebDAV-Pfad fehlt.';
    return false;
  }

  $fp = @fopen($destination, 'xb');
  if (!$fp)
  {
    $detail = 'Temporaere Datei konnte nicht angelegt werden.';
    return false;
  }

  $url = $credentials['base_url'].'/remote.php/dav/files/'.rawurlencode($credentials['user']).'/'.bratonien_tools_warmup_quote_path($webdav_path);
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $credentials['user'].':'.$credentials['password'],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FAILONERROR => false,
    CURLOPT_FILE => $fp,
    CURLOPT_USERAGENT => 'Bratonien-WebDAV-Warmup/0.9.7.1.8',
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
    $detail = 'Geladene Datei ist kein lesbares Bild.';
    return false;
  }
  return true;
}

function bratonien_tools_warmup_state_file($connection_id)
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.connection-'.(int)$connection_id.'.json';
}

function bratonien_tools_warmup_load_state($connection_id)
{
  $file = bratonien_tools_warmup_state_file($connection_id);
  if (!is_file($file) || !is_readable($file))
  {
    return array('albums'=>array(), 'images'=>array(), 'updated_at'=>0);
  }
  $raw = @file_get_contents($file);
  $state = $raw !== false ? json_decode($raw, true) : null;
  if (!is_array($state)) return array('albums'=>array(), 'images'=>array(), 'updated_at'=>0);
  if (!isset($state['albums']) || !is_array($state['albums'])) $state['albums'] = array();
  if (!isset($state['images']) || !is_array($state['images'])) $state['images'] = array();
  return $state;
}

function bratonien_tools_warmup_save_state($connection_id, array $state)
{
  $file = bratonien_tools_warmup_state_file($connection_id);
  $directory = dirname($file);
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory))
  {
    throw new RuntimeException('Warmup-State-Verzeichnis konnte nicht angelegt werden.');
  }
  $state['updated_at'] = time();
  $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
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

function bratonien_tools_warmup_scan_connection($connection_id)
{
  $images = array();
  $albums = array();
  $result = pwg_query('SELECT id, path, width, height, rotation, coi FROM '.IMAGES_TABLE.' ORDER BY id');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $image_id = (int)$row['id'];
    $source = bratonien_tools_webdav_materialize_source_info($image_id);
    if (!$source || (int)$source['connection_id'] !== (int)$connection_id) continue;

    $row['id'] = $image_id;
    $images[$image_id] = array('row'=>$row, 'source'=>$source, 'albums'=>array());
  }

  if (!$images) return array('images'=>array(), 'albums'=>array());

  $ids = array_keys($images);
  foreach (array_chunk($ids, 500) as $chunk)
  {
    $sql = 'SELECT image_id, category_id FROM '.IMAGE_CATEGORY_TABLE.' WHERE image_id IN ('.implode(',', array_map('intval', $chunk)).')';
    $rel = pwg_query($sql);
    while ($item = pwg_db_fetch_assoc($rel))
    {
      $image_id = (int)$item['image_id'];
      $category_id = (int)$item['category_id'];
      if (!isset($images[$image_id]) || $category_id < 1) continue;
      $images[$image_id]['albums'][$category_id] = true;
      $albums[$category_id] = true;
    }
  }

  foreach ($images as &$image)
  {
    $image['albums'] = array_map('intval', array_keys($image['albums']));
  }
  unset($image);

  return array('images'=>$images, 'albums'=>array_map('intval', array_keys($albums)));
}

function bratonien_tools_warmup_signature(array $source)
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

function bratonien_tools_warmup_select_ids(array $scan, array $state, $mode)
{
  $new_albums = array();
  $known_albums = array_fill_keys(array_map('intval', array_keys((array)$state['albums'])), true);
  foreach ($scan['albums'] as $category_id)
  {
    if (!isset($known_albums[(int)$category_id])) $new_albums[(int)$category_id] = true;
  }

  $selected = array();
  foreach ($scan['images'] as $image_id => $image)
  {
    $signature = bratonien_tools_warmup_signature($image['source']);
    $known_signature = (string)($state['images'][(string)$image_id]['signature'] ?? '');
    $in_new_album = false;
    foreach ($image['albums'] as $category_id)
    {
      if (isset($new_albums[(int)$category_id]))
      {
        $in_new_album = true;
        break;
      }
    }

    if ($mode === 'sync')
    {
      if ($in_new_album) $selected[$image_id] = $image;
    }
    else
    {
      if ($known_signature === '' || !hash_equals($known_signature, $signature)) $selected[$image_id] = $image;
    }
  }

  return array('images'=>$selected, 'new_albums'=>array_map('intval', array_keys($new_albums)));
}

function bratonien_tools_warmup_variants_for_stage(array $image_row, $stage)
{
  $src = new SrcImage($image_row);
  $variants = array();
  foreach (ImageStdParams::get_defined_type_map() as $type => $params)
  {
    $derivative = new DerivativeImage($params, $src);
    if ($derivative->same_as_source()) continue;
    $size = $derivative->get_size();
    $max_edge = max((int)($size[0] ?? 0), (int)($size[1] ?? 0));
    $is_priority = $max_edge > 0 && $max_edge <= 1920;
    if (($stage === 1 && $is_priority) || ($stage === 2 && !$is_priority))
    {
      $variants[] = array('name'=>'standard:'.$type, 'derivative'=>$derivative);
    }
  }
  foreach (ImageStdParams::$custom as $custom_key => $last_used)
  {
    $params = bratonien_tools_webdav_materialize_custom_params($custom_key);
    if (!$params) continue;
    $derivative = new DerivativeImage($params, $src);
    if ($derivative->same_as_source()) continue;
    $size = $derivative->get_size();
    $max_edge = max((int)($size[0] ?? 0), (int)($size[1] ?? 0));
    $is_priority = $max_edge > 0 && $max_edge <= 1920;
    if (($stage === 1 && $is_priority) || ($stage === 2 && !$is_priority))
    {
      $variants[] = array('name'=>'custom:'.$custom_key, 'derivative'=>$derivative);
    }
  }
  return $variants;
}

function bratonien_tools_warmup_i_url($target_path, $base_url)
{
  $derivative_root = PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR;
  if (strpos($target_path, $derivative_root) !== 0) return null;
  $location = ltrim(substr($target_path, strlen($derivative_root)), '/');
  if ($location === '') return null;
  $segments = array_map('rawurlencode', explode('/', $location));
  return rtrim((string)$base_url, '/').'/i.php?/'.implode('/', $segments);
}

function bratonien_tools_warmup_call_piwigo($url, &$detail=null)
{
  $detail = '';
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FAILONERROR => false,
    CURLOPT_WRITEFUNCTION => function($ch, $data) { return strlen($data); },
    CURLOPT_USERAGENT => 'Bratonien-WebDAV-Warmup/0.9.7.1.8',
  ));
  $ok = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($ok === false || $errno !== 0)
  {
    $detail = 'Piwigo-Aufruf fehlgeschlagen (cURL '.$errno.($error !== '' ? ': '.$error : '').').';
    return false;
  }
  if ($http < 200 || $http >= 400)
  {
    $detail = 'Piwigo i.php antwortete mit HTTP '.$http.'.';
    return false;
  }
  return true;
}

function bratonien_tools_warmup_acquire_sync_guard(array $credentials, &$handle=null, &$detail=null)
{
  $handle = null;
  $detail = '';
  $state_dir = rtrim((string)($credentials['config']['state_dir'] ?? ''), '/');
  if ($state_dir === '')
  {
    $detail = 'State-Verzeichnis der Verbindung fehlt.';
    return false;
  }
  $lock_path = $state_dir.'/webdav-sync.lock';
  $handle = @fopen($lock_path, 'c');
  if (!$handle)
  {
    $detail = 'WebDAV-Sync-Lock konnte nicht geöffnet werden.';
    return false;
  }
  if (!@flock($handle, LOCK_SH | LOCK_NB))
  {
    fclose($handle);
    $handle = null;
    $detail = 'WebDAV-Synchronisierung läuft; Warmup wird später fortgesetzt.';
    return false;
  }
  return true;
}

function bratonien_tools_warmup_source_lock($image_id, &$handle=null)
{
  $dir = PHPWG_ROOT_PATH.'upload/bratonien-webdav-materialize';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
  $path = $dir.'/image-'.(int)$image_id.'.lock';
  $handle = @fopen($path, 'c');
  if (!$handle) return false;
  return @flock($handle, LOCK_EX);
}

function bratonien_tools_warmup_release_lock(&$handle)
{
  if (is_resource($handle))
  {
    @flock($handle, LOCK_UN);
    fclose($handle);
  }
  $handle = null;
}

function bratonien_tools_warmup_validate_source(array $image_row, array $source, $temp_file, &$resolved=null, &$logical=null, &$detail=null)
{
  $resolved = null;
  $logical = bratonien_tools_warmup_absolute_source_path($image_row);
  $detail = '';
  if (!$logical)
  {
    $detail = 'Piwigo-Quellpfad fehlt.';
    return false;
  }
  $resolved = realpath($logical);
  if ($resolved === false || !is_file($resolved))
  {
    $detail = 'Piwigo-Placeholder konnte nicht aufgelöst werden.';
    return false;
  }
  $normalized = str_replace('\\', '/', $resolved);
  $expected = '/nc-webdav-source/connection-'.(int)$source['connection_id'].'/root-'.(int)$source['root_fileid'].'/';
  if (strpos($normalized, $expected) === false)
  {
    $detail = 'Aufgelöster Quellpfad gehört nicht zur erwarteten Verbindung/Wurzel.';
    return false;
  }
  if (!is_file($temp_file) || !is_readable($temp_file) || @getimagesize($temp_file) === false)
  {
    $detail = 'Batch-Quelldatei ist nicht vollständig lesbar.';
    return false;
  }
  return true;
}

function bratonien_tools_warmup_restore_placeholder($resolved, $backup, $staging, &$detail=null)
{
  $detail = '';
  @unlink($staging);
  if (is_file($resolved) || is_link($resolved))
  {
    if (!@unlink($resolved))
    {
      $detail = 'Temporäre Quelle konnte beim Restore nicht entfernt werden.';
      return false;
    }
  }
  if (!is_file($backup))
  {
    $detail = 'Placeholder-Backup fehlt beim Restore.';
    return false;
  }
  if (!@rename($backup, $resolved))
  {
    $detail = 'Placeholder konnte nicht restauriert werden.';
    return false;
  }
  clearstatcache(true, $resolved);
  if (!is_file($resolved) || filesize($resolved) < 1)
  {
    $detail = 'Restaurierter Placeholder ist nicht lesbar.';
    return false;
  }
  return true;
}

function bratonien_tools_warmup_process_image(array $image, $temp_file, array $credentials, $stage, array $settings)
{
  $image_row = $image['row'];
  $image_id = (int)$image_row['id'];

  $source = bratonien_tools_webdav_materialize_source_info($image_id);
  if (!$source || (int)$source['connection_id'] !== (int)$credentials['connection']['id'])
  {
    return array('ok'=>false, 'fatal'=>false, 'message'=>'Quellzuordnung hat sich seit Batchbeginn geändert.');
  }

  $image_lock = null;
  if (!bratonien_tools_warmup_source_lock($image_id, $image_lock))
  {
    return array('ok'=>false, 'fatal'=>false, 'message'=>'Bild-Lock konnte nicht gesetzt werden.');
  }

  $sync_lock = null;
  $detail = '';
  if (!bratonien_tools_warmup_acquire_sync_guard($credentials, $sync_lock, $detail))
  {
    bratonien_tools_warmup_release_lock($image_lock);
    return array('ok'=>false, 'fatal'=>false, 'defer'=>true, 'message'=>$detail);
  }

  $logical = null;
  $resolved = null;
  if (!bratonien_tools_warmup_validate_source($image_row, $source, $temp_file, $resolved, $logical, $detail))
  {
    bratonien_tools_warmup_release_lock($sync_lock);
    bratonien_tools_warmup_release_lock($image_lock);
    return array('ok'=>false, 'fatal'=>false, 'message'=>$detail);
  }

  $variants = bratonien_tools_warmup_variants_for_stage($image_row, $stage);
  $pending = array();
  foreach ($variants as $variant)
  {
    $target = $variant['derivative']->get_path();
    if ($target !== '' && is_file($target) && is_readable($target)) continue;
    $url = bratonien_tools_warmup_i_url($target, $settings['piwigo_base_url']);
    if ($url !== null) $pending[] = array('name'=>$variant['name'], 'target'=>$target, 'url'=>$url);
  }

  if (!$pending)
  {
    bratonien_tools_warmup_release_lock($sync_lock);
    bratonien_tools_warmup_release_lock($image_lock);
    return array('ok'=>true, 'generated'=>0, 'message'=>'Alle Derivate der Stufe bereits vorhanden.');
  }

  $request_id = substr(bin2hex(random_bytes(8)), 0, 8);
  $backup = $resolved.'.bratonien-placeholder-'.$request_id;
  $staging = $logical.'.bratonien-source-'.$request_id;
  @unlink($backup);
  @unlink($staging);

  if (!@copy($temp_file, $staging))
  {
    bratonien_tools_warmup_release_lock($sync_lock);
    bratonien_tools_warmup_release_lock($image_lock);
    return array('ok'=>false, 'fatal'=>false, 'message'=>'Batch-Quelle konnte nicht am Piwigo-Pfad bereitgestellt werden.');
  }

  $swapped = false;
  $generated = 0;
  try
  {
    if (!@rename($resolved, $backup)) throw new RuntimeException('Placeholder konnte nicht gesichert werden.');
    if (!@rename($staging, $resolved))
    {
      @rename($backup, $resolved);
      throw new RuntimeException('Temporäre Quelle konnte nicht eingesetzt werden.');
    }
    $swapped = true;
    clearstatcache(true, $resolved);
    if (@getimagesize($resolved) === false) throw new RuntimeException('Temporäre Quelle ist am Piwigo-Pfad nicht als Bild lesbar.');

    foreach ($pending as $variant)
    {
      $call_detail = '';
      if (!bratonien_tools_warmup_call_piwigo($variant['url'], $call_detail))
      {
        throw new RuntimeException($variant['name'].': '.$call_detail);
      }
      clearstatcache(true, $variant['target']);
      if (!is_file($variant['target']) || !is_readable($variant['target']))
      {
        throw new RuntimeException($variant['name'].': Piwigo hat kein Cache-Derivat erzeugt.');
      }
      $generated++;
    }
  }
  catch (Throwable $e)
  {
    $restore_detail = '';
    $restored = $swapped ? bratonien_tools_warmup_restore_placeholder($resolved, $backup, $staging, $restore_detail) : true;
    if (!$swapped) @unlink($staging);
    bratonien_tools_warmup_release_lock($sync_lock);
    bratonien_tools_warmup_release_lock($image_lock);
    if (!$restored)
    {
      return array('ok'=>false, 'fatal'=>true, 'message'=>$e->getMessage().' Restore fehlgeschlagen: '.$restore_detail);
    }
    return array('ok'=>false, 'fatal'=>false, 'message'=>$e->getMessage());
  }

  $restore_detail = '';
  $restored = bratonien_tools_warmup_restore_placeholder($resolved, $backup, $staging, $restore_detail);
  bratonien_tools_warmup_release_lock($sync_lock);
  bratonien_tools_warmup_release_lock($image_lock);
  if (!$restored)
  {
    return array('ok'=>false, 'fatal'=>true, 'message'=>$restore_detail);
  }

  return array('ok'=>true, 'generated'=>$generated, 'message'=>'Piwigo-Derivate erzeugt.');
}

function bratonien_tools_warmup_run($connection_id, $mode)
{
  $settings = bratonien_tools_get_webdav_warmup_settings();
  if (empty($settings['enabled']))
  {
    bratonien_tools_warmup_log('disabled', array('connection_id'=>$connection_id));
    return 0;
  }

  $credentials = bratonien_tools_warmup_connection_credentials($connection_id);
  if (!$credentials) throw new RuntimeException('WebDAV-Verbindung oder Zugangsdaten konnten nicht geladen werden.');

  $state = bratonien_tools_warmup_load_state($connection_id);
  $scan = bratonien_tools_warmup_scan_connection($connection_id);
  $selection = bratonien_tools_warmup_select_ids($scan, $state, $mode);
  $selected = $selection['images'];

  bratonien_tools_warmup_log('scan', array(
    'connection_id'=>$connection_id,
    'mode'=>$mode,
    'albums'=>count($scan['albums']),
    'new_albums'=>count($selection['new_albums']),
    'images'=>count($scan['images']),
    'selected'=>count($selected),
    'batch_size'=>$settings['batch_size'],
  ));

  if (!$selected)
  {
    foreach ($scan['albums'] as $category_id) $state['albums'][(string)$category_id] = array('seen_at'=>time());
    bratonien_tools_warmup_save_state($connection_id, $state);
    return 0;
  }

  $temp_root = PHPWG_ROOT_PATH.'upload/bratonien-webdav-warmup';
  if (!is_dir($temp_root) && !@mkdir($temp_root, 0775, true) && !is_dir($temp_root)) throw new RuntimeException('Warmup-Temp-Verzeichnis konnte nicht angelegt werden.');

  $successful = array();
  $failed = 0;
  $ids = array_keys($selected);
  $batch_number = 0;

  foreach (array_chunk($ids, max(1, (int)$settings['batch_size'])) as $batch_ids)
  {
    $batch_number++;
    $batch_dir = $temp_root.'/connection-'.(int)$connection_id.'-'.date('YmdHis').'-'.bin2hex(random_bytes(4));
    if (!@mkdir($batch_dir, 0775, true) && !is_dir($batch_dir)) throw new RuntimeException('Batch-Verzeichnis konnte nicht angelegt werden.');

    $downloaded = array();
    foreach ($batch_ids as $image_id)
    {
      $image = $selected[$image_id];
      $source = $image['source'];
      $temp_file = $batch_dir.'/image-'.(int)$image_id.'.img';
      $detail = '';
      if (bratonien_tools_warmup_download($source, $credentials, $temp_file, $detail))
      {
        $downloaded[$image_id] = $temp_file;
      }
      else
      {
        $failed++;
        bratonien_tools_warmup_log('download_failed', array('image_id'=>$image_id, 'detail'=>$detail));
      }
    }

    bratonien_tools_warmup_log('batch_downloaded', array('batch'=>$batch_number, 'requested'=>count($batch_ids), 'downloaded'=>count($downloaded)));

    foreach (array(1, 2) as $stage)
    {
      foreach ($downloaded as $image_id => $temp_file)
      {
        $result = bratonien_tools_warmup_process_image($selected[$image_id], $temp_file, $credentials, $stage, $settings);
        bratonien_tools_warmup_log('image_stage', array(
          'batch'=>$batch_number,
          'stage'=>$stage,
          'image_id'=>$image_id,
          'ok'=>!empty($result['ok']),
          'generated'=>(int)($result['generated'] ?? 0),
          'message'=>(string)($result['message'] ?? ''),
        ));
        if (!empty($result['fatal']))
        {
          throw new RuntimeException('Sicherheitsabbruch bei Bild #'.$image_id.': '.(string)$result['message']);
        }
        if (empty($result['ok']))
        {
          $failed++;
          continue;
        }
        if ($stage === 2) $successful[$image_id] = true;
      }
    }

    foreach ($downloaded as $temp_file) @unlink($temp_file);
    @rmdir($batch_dir);
  }

  foreach ($scan['albums'] as $category_id) $state['albums'][(string)$category_id] = array('seen_at'=>time());
  foreach ($successful as $image_id => $true)
  {
    if (!isset($scan['images'][$image_id])) continue;
    $state['images'][(string)$image_id] = array(
      'signature'=>bratonien_tools_warmup_signature($scan['images'][$image_id]['source']),
      'completed_at'=>time(),
    );
  }
  bratonien_tools_warmup_save_state($connection_id, $state);

  bratonien_tools_warmup_log('complete', array('connection_id'=>$connection_id, 'successful'=>count($successful), 'failed'=>$failed));
  return $failed > 0 ? 2 : 0;
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
  exit(bratonien_tools_warmup_run($connection_id, $mode));
}
catch (Throwable $e)
{
  fwrite(STDERR, '[BRAT-WARMUP] fatal '.$e->getMessage()."\n");
  exit(1);
}
