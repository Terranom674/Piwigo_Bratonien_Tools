<?php
if (PHP_SAPI !== 'cli')
{
  http_response_code(404);
  exit;
}

$piwigo_root = realpath(dirname(__DIR__, 4));
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
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Cache-Worker/0.9.7.1.30';
$_SERVER['HTTPS'] = 'off';

require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
if (!defined('BRATONIEN_TOOLS_PATH'))
{
  fwrite(STDERR, "Bratonien Tools ist nicht aktiv.\n");
  exit(1);
}
require_once(BRATONIEN_TOOLS_PATH.'include/nc_connector.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_source_index.inc.php');
require_once(BRATONIEN_TOOLS_PATH.'include/webdav_warmup_settings.inc.php');

function bratonien_tools_cache_warmup_log($event, array $fields=array())
{
  $parts = array('[BRAT-WORKER]', $event);
  foreach ($fields as $key=>$value)
  {
    if (is_bool($value)) $value = $value ? '1' : '0';
    elseif ($value === null) $value = 'NULL';
    elseif (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_SLASHES);
    $parts[] = $key.'='.str_replace(array("\r", "\n"), array('\\r', '\\n'), (string)$value);
  }
  fwrite(STDOUT, implode(' ', $parts)."\n");
}

function bratonien_tools_cache_warmup_status_file($connection_id)
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.status-'.(int)$connection_id.'.json';
}

function bratonien_tools_cache_warmup_cancel_file($connection_id)
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.cancel-'.(int)$connection_id;
}

function bratonien_tools_cache_warmup_cancel_pending($connection_id)
{
  return is_file(bratonien_tools_cache_warmup_cancel_file($connection_id));
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
    throw new RuntimeException('Worker-Statusverzeichnis konnte nicht angelegt werden.');
  }
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('Worker-Status konnte nicht serialisiert werden.');
  $tmp = $file.'.tmp-'.bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, $json."\n", LOCK_EX) === false) throw new RuntimeException('Worker-Status konnte nicht geschrieben werden.');
  @chmod($tmp, 0664);
  if (!@rename($tmp, $file))
  {
    @unlink($tmp);
    throw new RuntimeException('Worker-Status konnte nicht atomar gespeichert werden.');
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
  $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
  $gallery_root = rtrim((string)($config['parallel_gallery_root'] ?? ''), '/');
  if ($base_url === '' || $user === '' || $password === '' || $state_dir === '' || $gallery_root === '') return null;

  return array(
    'connection'=>$connection,
    'config'=>$config,
    'base_url'=>$base_url,
    'user'=>$user,
    'password'=>$password,
    'state_dir'=>$state_dir,
    'gallery_root'=>$gallery_root,
  );
}

function bratonien_tools_cache_warmup_load_json($file, $label)
{
  if (!is_file($file) || !is_readable($file)) throw new RuntimeException($label.' fehlt oder ist nicht lesbar: '.$file);
  $raw = @file_get_contents($file);
  $data = $raw !== false ? json_decode($raw, true) : null;
  if (!is_array($data)) throw new RuntimeException($label.' enthält kein gültiges JSON.');
  return $data;
}

function bratonien_tools_cache_warmup_normalize_path($path)
{
  return rtrim(str_replace('\\', '/', (string)$path), '/');
}

function bratonien_tools_cache_warmup_scan($connection_id, array $credentials)
{
  $state_dir = $credentials['state_dir'];
  $gallery_root = $credentials['gallery_root'];
  $mapping = bratonien_tools_cache_warmup_load_json($state_dir.'/webdav-map.json', 'WebDAV-Mapping');
  $files = isset($mapping['files']) && is_array($mapping['files']) ? $mapping['files'] : array();
  if (!$files) throw new RuntimeException('WebDAV-Mapping enthält keine Quellen.');
  if (!is_dir($gallery_root)) throw new RuntimeException('Shadow-Tree fehlt: '.$gallery_root);

  $by_source = array();
  foreach ($files as $path=>$meta)
  {
    if (!is_array($meta) || (string)($meta['kind'] ?? '') !== 'file') continue;
    $normalized = bratonien_tools_cache_warmup_normalize_path($path);
    $by_source[$normalized] = $meta;
  }

  $sources = array();
  $seen_targets = array();
  $gallery_prefix = bratonien_tools_cache_warmup_normalize_path(realpath($gallery_root) ?: $gallery_root).'/';
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($gallery_root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
  );

  foreach ($iterator as $item)
  {
    $shadow_absolute = $item->getPathname();
    if (!is_link($shadow_absolute)) continue;
    $resolved = realpath($shadow_absolute);
    if ($resolved === false || !is_file($resolved)) throw new RuntimeException('Shadow-Link zeigt auf keine lesbare Quelle: '.$shadow_absolute);
    $resolved = bratonien_tools_cache_warmup_normalize_path($resolved);
    if (!isset($by_source[$resolved])) throw new RuntimeException('Shadow-Link besitzt keinen Eintrag im Connector-Mapping: '.$shadow_absolute);
    if (isset($seen_targets[$resolved])) throw new RuntimeException('Sicherheitsabbruch: dieselbe Connector-Quelle ist mehrfach im Shadow-Tree verknüpft: '.$resolved);
    $seen_targets[$resolved] = true;

    $shadow_normalized = bratonien_tools_cache_warmup_normalize_path($shadow_absolute);
    if (strpos($shadow_normalized.'/', $gallery_prefix) !== 0) throw new RuntimeException('Shadow-Datei liegt außerhalb der erwarteten Galerie-Wurzel.');
    $shadow_relative = ltrim(substr($shadow_normalized, strlen(rtrim($gallery_prefix, '/'))), '/');
    if ($shadow_relative === '') throw new RuntimeException('Leerer relativer Shadow-Pfad erkannt.');

    $meta = $by_source[$resolved];
    $root_fileid = 0;
    if (preg_match('#/root-([0-9]+)/#', $resolved.'/', $m)) $root_fileid = (int)$m[1];
    if ($root_fileid < 1) throw new RuntimeException('Connector-Wurzel konnte aus der Placeholder-Quelle nicht bestimmt werden: '.$resolved);

    $source = array(
      'connection_id'=>(int)$connection_id,
      'root_fileid'=>$root_fileid,
      'fileid'=>(int)($meta['fileid'] ?? 0),
      'webdav_path'=>trim((string)($meta['webdav_path'] ?? ''), '/'),
      'content_type'=>(string)($meta['content_type'] ?? ''),
      'size'=>(int)($meta['size'] ?? 0),
      'etag'=>(string)($meta['etag'] ?? ''),
      'width'=>(int)($meta['width'] ?? 0),
      'height'=>(int)($meta['height'] ?? 0),
    );
    if ($source['webdav_path'] === '') throw new RuntimeException('Connector-Mapping enthält für '.$shadow_relative.' keinen WebDAV-Pfad.');

    $key = bratonien_tools_webdav_source_index_key($source);
    if (isset($sources[$key])) throw new RuntimeException('Doppelte Quellenidentität im Shadow-Tree: '.$key);
    $sources[$key] = array(
      'index_key'=>$key,
      'signature'=>bratonien_tools_webdav_source_index_signature($source),
      'source'=>$source,
      'source_path'=>$resolved,
      'shadow_absolute'=>$shadow_absolute,
      'shadow_relative'=>$shadow_relative,
    );
  }

  return array('sources'=>$sources);
}

function bratonien_tools_cache_warmup_select(array $scan, array $index, $mode)
{
  if ($mode === 'rebuild') return $scan['sources'];
  $selected = array();
  foreach ($scan['sources'] as $key=>$item)
  {
    $entry = isset($index['sources'][$key]) && is_array($index['sources'][$key]) ? $index['sources'][$key] : null;
    if ($entry === null || !bratonien_tools_webdav_source_index_is_current($entry, $item['signature'])) $selected[$key] = $item;
  }
  return $selected;
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
    CURLOPT_USERAGENT=>'Bratonien-WebDAV-Cache-Worker/0.9.7.1.30',
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
  $handle = @fopen($credentials['state_dir'].'/webdav-sync.lock', 'c');
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

function bratonien_tools_cache_warmup_source_lock($index_key, &$handle=null)
{
  $dir = PHPWG_ROOT_PATH.'upload/bratonien-webdav-materialize';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
  $handle = @fopen($dir.'/source-'.sha1((string)$index_key).'.lock', 'c');
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

function bratonien_tools_cache_warmup_fresh_source(array $item, array $credentials)
{
  $mapping = bratonien_tools_cache_warmup_load_json($credentials['state_dir'].'/webdav-map.json', 'WebDAV-Mapping');
  $files = isset($mapping['files']) && is_array($mapping['files']) ? $mapping['files'] : array();
  $wanted = bratonien_tools_cache_warmup_normalize_path($item['source_path']);
  foreach ($files as $path=>$meta)
  {
    if (bratonien_tools_cache_warmup_normalize_path($path) !== $wanted || !is_array($meta)) continue;
    $source = array(
      'connection_id'=>(int)$item['source']['connection_id'],
      'root_fileid'=>(int)$item['source']['root_fileid'],
      'fileid'=>(int)($meta['fileid'] ?? 0),
      'webdav_path'=>trim((string)($meta['webdav_path'] ?? ''), '/'),
      'content_type'=>(string)($meta['content_type'] ?? ''),
      'size'=>(int)($meta['size'] ?? 0),
      'etag'=>(string)($meta['etag'] ?? ''),
      'width'=>(int)($meta['width'] ?? 0),
      'height'=>(int)($meta['height'] ?? 0),
    );
    return $source;
  }
  return null;
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

function bratonien_tools_cache_warmup_call_piwigo(array $item, $stage, &$detail=null)
{
  $detail = '';
  $bridge = BRATONIEN_TOOLS_PATH.'runtime/lib/piwigo-cache-request-by-shadow.php';
  if (!is_file($bridge)) { $detail = 'Piwigo-Shadow-Bridge fehlt.'; return false; }
  if (!function_exists('exec')) { $detail = 'PHP exec() ist deaktiviert.'; return false; }
  $output = array();
  $exit = 1;
  $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($bridge)
    .' --connection-id='.(int)$item['source']['connection_id']
    .' --shadow-relative='.escapeshellarg($item['shadow_relative'])
    .' --stage='.(int)$stage.' 2>&1';
  @exec($command, $output, $exit);
  if ($exit !== 0)
  {
    $detail = $output ? implode(' ', array_slice($output, -4)) : 'Piwigo-Shadow-Bridge Exit '.$exit;
    return false;
  }
  $detail = $output ? (string)end($output) : '';
  return true;
}

function bratonien_tools_cache_warmup_process(array $item, $temp_file, array $credentials, $stage)
{
  $source_lock = null;
  if (!bratonien_tools_cache_warmup_source_lock($item['index_key'], $source_lock))
  {
    return array('ok'=>false, 'defer'=>true, 'message'=>'Quellen-Lock konnte nicht gesetzt werden.');
  }
  $sync_lock = null;
  $detail = '';
  if (!bratonien_tools_cache_warmup_sync_guard($credentials, $sync_lock, $detail))
  {
    bratonien_tools_cache_warmup_unlock($source_lock);
    return array('ok'=>false, 'defer'=>true, 'message'=>$detail);
  }

  $fresh = bratonien_tools_cache_warmup_fresh_source($item, $credentials);
  if (!$fresh || bratonien_tools_webdav_source_index_signature($fresh) !== $item['signature'])
  {
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($source_lock);
    return array('ok'=>false, 'defer'=>true, 'message'=>'Connector-Mapping hat sich seit dem Batch-Download geändert.');
  }
  if (!is_link($item['shadow_absolute']) || bratonien_tools_cache_warmup_normalize_path(realpath($item['shadow_absolute']) ?: '') !== bratonien_tools_cache_warmup_normalize_path($item['source_path']))
  {
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($source_lock);
    return array('ok'=>false, 'defer'=>true, 'message'=>'Shadow-Tree hat sich seit dem Batch-Download geändert.');
  }

  $source_path = $item['source_path'];
  if (!is_file($source_path) || !is_readable($source_path) || !is_file($temp_file) || @getimagesize($temp_file) === false)
  {
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($source_lock);
    return array('ok'=>false, 'message'=>'Placeholder oder Batch-Quelle ist nicht vollständig lesbar.');
  }

  $placeholder_size = filesize($source_path);
  $placeholder_hash = @sha1_file($source_path);
  if (!is_string($placeholder_hash))
  {
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($source_lock);
    return array('ok'=>false, 'fatal'=>true, 'message'=>'Placeholder konnte vor dem Swap nicht verifiziert werden.');
  }

  $request_id = substr(bin2hex(random_bytes(8)), 0, 8);
  $backup = $source_path.'.bratonien-placeholder-'.$request_id;
  $staging = $source_path.'.bratonien-source-'.$request_id;
  if (file_exists($backup) || file_exists($staging))
  {
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($source_lock);
    return array('ok'=>false, 'fatal'=>true, 'message'=>'Unerwartete Swap-Datei ist bereits vorhanden.');
  }
  if (!@copy($temp_file, $staging) || @getimagesize($staging) === false)
  {
    @unlink($staging);
    bratonien_tools_cache_warmup_unlock($sync_lock);
    bratonien_tools_cache_warmup_unlock($source_lock);
    return array('ok'=>false, 'message'=>'Batch-Quelle konnte nicht sicher neben dem Placeholder bereitgestellt werden.');
  }

  $swapped = false;
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
    if (@getimagesize($source_path) === false) throw new RuntimeException('Shadow-Quelle enthält nach dem Swap kein lesbares Bild.');

    $bridge_detail = '';
    if (!bratonien_tools_cache_warmup_call_piwigo($item, $stage, $bridge_detail))
    {
      throw new RuntimeException('Piwigo-Verarbeitung fehlgeschlagen: '.$bridge_detail);
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
    bratonien_tools_cache_warmup_unlock($source_lock);
    if (!$restored) return array('ok'=>false, 'fatal'=>true, 'message'=>$e->getMessage().' RESTORE FEHLGESCHLAGEN: '.$restore_detail);
    return array('ok'=>false, 'message'=>$e->getMessage());
  }

  $restore_detail = '';
  $restored = bratonien_tools_cache_warmup_restore($source_path, $backup, $staging, $placeholder_size, $placeholder_hash, $restore_detail);
  bratonien_tools_cache_warmup_unlock($sync_lock);
  bratonien_tools_cache_warmup_unlock($source_lock);
  if (!$restored) return array('ok'=>false, 'fatal'=>true, 'message'=>'RESTORE FEHLGESCHLAGEN: '.$restore_detail);
  return array('ok'=>true, 'message'=>'Piwigo hat den Shadow-Pfad verarbeitet.');
}

function bratonien_tools_cache_warmup_stage_pending(array $selected, array $index, $stage)
{
  $pending = array();
  $field = $stage === 1 ? 'stage1_signature' : 'stage2_signature';
  foreach ($selected as $key=>$item)
  {
    $entry = isset($index['sources'][$key]) && is_array($index['sources'][$key]) ? $index['sources'][$key] : array();
    if (!isset($entry[$field]) || !hash_equals((string)$entry[$field], $item['signature'])) $pending[$key] = $item;
  }
  return $pending;
}

function bratonien_tools_cache_warmup_stage_completed_count(array $selected, array $index, $stage)
{
  return count($selected) - count(bratonien_tools_cache_warmup_stage_pending($selected, $index, $stage));
}

function bratonien_tools_cache_warmup_run_stage($connection_id, $stage, array $selected, array &$index, array $credentials, $batch_size, $priority_file='')
{
  $pending = bratonien_tools_cache_warmup_stage_pending($selected, $index, $stage);
  if (!$pending) return array('failed'=>0, 'deferred'=>0, 'waiting'=>false, 'preempted'=>false, 'cancelled'=>false, 'completed'=>0);
  if (bratonien_tools_cache_warmup_cancel_pending($connection_id))
  {
    return array('failed'=>0, 'deferred'=>0, 'waiting'=>false, 'preempted'=>false, 'cancelled'=>true, 'completed'=>0);
  }
  if ($stage === 2 && bratonien_tools_cache_warmup_priority_pending($priority_file))
  {
    return array('failed'=>0, 'deferred'=>0, 'waiting'=>false, 'preempted'=>true, 'cancelled'=>false, 'completed'=>0);
  }

  $temp_root = PHPWG_ROOT_PATH.'upload/bratonien-webdav-warmup';
  if (!is_dir($temp_root) && !@mkdir($temp_root, 0775, true) && !is_dir($temp_root)) throw new RuntimeException('Worker-Temp-Verzeichnis konnte nicht angelegt werden.');
  $failed = 0;
  $deferred = 0;
  $completed = 0;
  $batch_number = 0;

  foreach (array_chunk(array_keys($pending), max(1, (int)$batch_size)) as $keys)
  {
    // Harte Batch-Grenze: Sobald ein Abbruchsignal existiert, darf dieser
    // Batch gar nicht mehr begonnen und damit auch kein weiteres Original
    // aus Nextcloud geladen werden.
    if (bratonien_tools_cache_warmup_cancel_pending($connection_id))
    {
      return array('failed'=>$failed, 'deferred'=>$deferred, 'waiting'=>false, 'preempted'=>false, 'cancelled'=>true, 'completed'=>$completed);
    }

    if ($stage === 2 && bratonien_tools_cache_warmup_priority_pending($priority_file))
    {
      return array('failed'=>$failed, 'deferred'=>$deferred, 'waiting'=>false, 'preempted'=>true, 'cancelled'=>false, 'completed'=>$completed);
    }

    // Der Connector-Lock wird VOR jedem Download geprueft und fuer den
    // kompletten Batch gemeinsam gehalten. Ist der Connector aktiv, werden
    // exakt null Bilder dieses Batches geladen.
    $batch_sync_lock = null;
    $batch_sync_detail = '';
    if (!bratonien_tools_cache_warmup_sync_guard($credentials, $batch_sync_lock, $batch_sync_detail))
    {
      bratonien_tools_cache_warmup_status($connection_id, 'waiting', 'Connector-Synchronisierung läuft. Der nächste Batch startet erst danach; es wurden für diesen Batch keine Nextcloud-Originale geladen.', array(
        'stage'=>$stage,
        'batch'=>$batch_number + 1,
        'selected_total'=>count($selected),
        'stage_completed'=>$completed,
        'deferred_by_connector'=>true,
      ));
      return array('failed'=>$failed, 'deferred'=>$deferred, 'waiting'=>true, 'preempted'=>false, 'cancelled'=>false, 'completed'=>$completed);
    }

    $batch_number++;
    $dir = $temp_root.'/connection-'.(int)$connection_id.'-stage'.$stage.'-'.date('YmdHis').'-'.bin2hex(random_bytes(4));
    if (!@mkdir($dir, 0775, true) && !is_dir($dir))
    {
      bratonien_tools_cache_warmup_unlock($batch_sync_lock);
      throw new RuntimeException('Worker-Batch-Verzeichnis konnte nicht angelegt werden.');
    }

    $downloads = array();
    foreach ($keys as $key)
    {
      $file = $dir.'/'.sha1($key).'.img';
      $detail = '';
      if (bratonien_tools_cache_warmup_download($pending[$key]['source'], $credentials, $file, $detail)) $downloads[$key] = $file;
      else
      {
        $failed++;
        bratonien_tools_cache_warmup_log('download_failed', array('stage'=>$stage, 'source'=>$key, 'detail'=>$detail));
      }
    }

    $cancel_requested = bratonien_tools_cache_warmup_cancel_pending($connection_id);
    bratonien_tools_cache_warmup_status(
      $connection_id,
      $cancel_requested ? 'cancelling' : 'running',
      $cancel_requested
        ? 'Abbruch angefordert. Der bereits geladene aktuelle Batch wird vollständig beendet; danach ist Schluss.'
        : 'Piwigo verarbeitet WebDAV-Quellen aus dem Worker-Index.',
      array(
        'stage'=>$stage,
        'batch'=>$batch_number,
        'batch_requested'=>count($keys),
        'batch_downloaded'=>count($downloads),
        'selected_total'=>count($selected),
        'stage_completed'=>$completed,
        'cancel_requested'=>$cancel_requested,
      )
    );

    foreach ($downloads as $key=>$file)
    {
      $result = bratonien_tools_cache_warmup_process($pending[$key], $file, $credentials, $stage);
      bratonien_tools_cache_warmup_log('source', array('stage'=>$stage, 'source'=>$key, 'ok'=>!empty($result['ok']), 'defer'=>!empty($result['defer']), 'message'=>(string)($result['message'] ?? '')));
      if (!empty($result['fatal']))
      {
        foreach ($downloads as $cleanup) @unlink($cleanup);
        @rmdir($dir);
        bratonien_tools_cache_warmup_unlock($batch_sync_lock);
        throw new RuntimeException('Sicherheitsabbruch bei '.$key.': '.(string)$result['message']);
      }
      if (empty($result['ok']))
      {
        // Temporäre Sperren oder parallel belegte Quellen sind kein Fehler.
        // Der Index bleibt offen und der nächste Lauf versucht nur diese
        // Quelle erneut.
        if (!empty($result['defer'])) $deferred++;
        else $failed++;
        continue;
      }

      $item = $pending[$key];
      $existing = isset($index['sources'][$key]) && is_array($index['sources'][$key]) ? $index['sources'][$key] : array();
      $metadata = bratonien_tools_webdav_source_index_metadata($item['source'], $item['shadow_relative']);
      $index['sources'][$key] = array_merge($existing, $metadata);
      $index['sources'][$key][$stage === 1 ? 'stage1_signature' : 'stage2_signature'] = $item['signature'];
      bratonien_tools_webdav_source_index_save($credentials['state_dir'], $connection_id, $index);
      $completed++;
    }

    foreach ($downloads as $file) @unlink($file);
    @rmdir($dir);
    bratonien_tools_cache_warmup_unlock($batch_sync_lock);

    if (bratonien_tools_cache_warmup_cancel_pending($connection_id))
    {
      return array('failed'=>$failed, 'deferred'=>$deferred, 'waiting'=>false, 'preempted'=>false, 'cancelled'=>true, 'completed'=>$completed);
    }

    if ($stage === 2 && bratonien_tools_cache_warmup_priority_pending($priority_file))
    {
      return array('failed'=>$failed, 'deferred'=>$deferred, 'waiting'=>false, 'preempted'=>true, 'cancelled'=>false, 'completed'=>$completed);
    }
  }

  return array('failed'=>$failed, 'deferred'=>$deferred, 'waiting'=>false, 'preempted'=>false, 'cancelled'=>false, 'completed'=>$completed);
}

function bratonien_tools_cache_warmup_run($connection_id, $mode)
{
  $settings = bratonien_tools_get_webdav_warmup_settings();
  if (!in_array($mode, array('manual','rebuild'), true) && empty($settings['enabled'])) return 0;

  $credentials = bratonien_tools_cache_warmup_credentials($connection_id);
  if (!$credentials) throw new RuntimeException('WebDAV-Verbindung, Runtime-Pfade oder Zugangsdaten konnten nicht geladen werden.');
  $state_dir = $credentials['state_dir'];
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
    if ($mode === 'sync' && is_file($priority_file)) @unlink($priority_file);

    $index = bratonien_tools_webdav_source_index_load($state_dir, $connection_id);
    $index_created = false;
    if ($index === null)
    {
      $index = bratonien_tools_webdav_source_index_empty($connection_id);
      $index_created = true;
    }

    if ($mode === 'periodic')
    {
      $last = (int)($index['last_periodic_at'] ?? 0);
      $interval = max(1, (int)$settings['periodic_hours']) * 3600;
      if ($last > 0 && time() - $last < $interval) return 0;
    }

    $scan = bratonien_tools_cache_warmup_scan($connection_id, $credentials);
    $removed = bratonien_tools_webdav_source_index_prune($index, array_keys($scan['sources']));
    $selected = bratonien_tools_cache_warmup_select($scan, $index, $mode);

    bratonien_tools_cache_warmup_status($connection_id, 'scan', 'Worker-Index wurde ausschließlich mit dem aktuellen Shadow-Tree abgeglichen.', array(
      'mode'=>$mode,
      'shadow_sources'=>count($scan['sources']),
      'selected'=>count($selected),
      'removed_from_index'=>$removed,
      'index_created'=>$index_created,
    ));

    if (!$selected)
    {
      if ($mode === 'periodic' || $mode === 'manual') $index['last_periodic_at'] = time();
      bratonien_tools_webdav_source_index_save($state_dir, $connection_id, $index);
      @unlink(bratonien_tools_cache_warmup_cancel_file($connection_id));
      bratonien_tools_cache_warmup_status($connection_id, 'complete', 'Keine neuen oder geänderten Shadow-Quellen gefunden.', array('mode'=>$mode, 'shadow_sources'=>count($scan['sources'])));
      return 0;
    }

    if ($mode === 'rebuild')
    {
      foreach ($selected as $key=>$item)
      {
        if (!isset($index['sources'][$key]) || !is_array($index['sources'][$key])) $index['sources'][$key] = array();
        unset($index['sources'][$key]['stage1_signature'], $index['sources'][$key]['stage2_signature']);
      }
    }

    $stage1 = bratonien_tools_cache_warmup_run_stage($connection_id, 1, $selected, $index, $credentials, $settings['batch_size'], $priority_file);
    if (!empty($stage1['cancelled']))
    {
      bratonien_tools_webdav_source_index_save($state_dir, $connection_id, $index);
      @unlink(bratonien_tools_cache_warmup_cancel_file($connection_id));
      bratonien_tools_cache_warmup_status($connection_id, 'cancelled', 'Cache-Aufbau nach dem vollständig abgeschlossenen aktuellen Batch beendet.', array(
        'mode'=>$mode,
        'shadow_sources'=>count($scan['sources']),
        'selected'=>count($selected),
        'stage1_completed'=>bratonien_tools_cache_warmup_stage_completed_count($selected, $index, 1),
        'stage2_completed'=>bratonien_tools_cache_warmup_stage_completed_count($selected, $index, 2),
        'stage1_failed'=>(int)$stage1['failed'],
        'stage1_deferred'=>(int)$stage1['deferred'],
        'stage2_failed'=>0,
        'stage2_deferred'=>0,
      ));
      return 0;
    }
    if (!empty($stage1['waiting']))
    {
      bratonien_tools_webdav_source_index_save($state_dir, $connection_id, $index);
      return 0;
    }

    $stage2_candidates = array();
    foreach ($selected as $key=>$item)
    {
      $entry = isset($index['sources'][$key]) && is_array($index['sources'][$key]) ? $index['sources'][$key] : array();
      if (isset($entry['stage1_signature']) && hash_equals((string)$entry['stage1_signature'], $item['signature'])) $stage2_candidates[$key] = $item;
    }
    $stage2 = bratonien_tools_cache_warmup_run_stage($connection_id, 2, $stage2_candidates, $index, $credentials, $settings['batch_size'], $priority_file);

    if (!empty($stage2['cancelled']))
    {
      bratonien_tools_webdav_source_index_save($state_dir, $connection_id, $index);
      @unlink(bratonien_tools_cache_warmup_cancel_file($connection_id));
      bratonien_tools_cache_warmup_status($connection_id, 'cancelled', 'Cache-Aufbau nach dem vollständig abgeschlossenen aktuellen Batch beendet.', array(
        'mode'=>$mode,
        'shadow_sources'=>count($scan['sources']),
        'selected'=>count($selected),
        'stage1_completed'=>bratonien_tools_cache_warmup_stage_completed_count($selected, $index, 1),
        'stage2_completed'=>bratonien_tools_cache_warmup_stage_completed_count($selected, $index, 2),
        'stage1_failed'=>(int)$stage1['failed'],
        'stage1_deferred'=>(int)$stage1['deferred'],
        'stage2_failed'=>(int)$stage2['failed'],
        'stage2_deferred'=>(int)$stage2['deferred'],
      ));
      return 0;
    }
    if (!empty($stage2['waiting']))
    {
      bratonien_tools_webdav_source_index_save($state_dir, $connection_id, $index);
      return 0;
    }

    if (!empty($stage2['preempted']))
    {
      bratonien_tools_webdav_source_index_save($state_dir, $connection_id, $index);
      bratonien_tools_cache_warmup_status($connection_id, 'preempted', 'Stufe 2 wurde nach einem vollständigen Batch für neue Connector-Inhalte freigegeben.', array(
        'mode'=>$mode,
        'selected'=>count($selected),
        'stage1_completed'=>bratonien_tools_cache_warmup_stage_completed_count($selected, $index, 1),
        'stage2_completed'=>bratonien_tools_cache_warmup_stage_completed_count($selected, $index, 2),
        'stage1_failed'=>(int)$stage1['failed'],
        'stage1_deferred'=>(int)$stage1['deferred'],
        'stage2_failed'=>(int)$stage2['failed'],
        'stage2_deferred'=>(int)$stage2['deferred'],
      ));
      return 0;
    }

    if ($mode === 'periodic' || $mode === 'manual') $index['last_periodic_at'] = time();
    bratonien_tools_webdav_source_index_save($state_dir, $connection_id, $index);
    @unlink(bratonien_tools_cache_warmup_cancel_file($connection_id));

    $failed = (int)$stage1['failed'] + (int)$stage2['failed'];
    $deferred = (int)$stage1['deferred'] + (int)$stage2['deferred'];
    if ($failed > 0 || $deferred > 0)
    {
      bratonien_tools_cache_warmup_status($connection_id, 'partial', 'Worker-Lauf beendet; einzelne Quellen bleiben offen und werden beim nächsten Lauf erneut versucht. Der übrige Bestand wurde weiterverarbeitet.', array(
        'mode'=>$mode,
        'shadow_sources'=>count($scan['sources']),
        'selected'=>count($selected),
        'stage1_completed'=>bratonien_tools_cache_warmup_stage_completed_count($selected, $index, 1),
        'stage2_completed'=>bratonien_tools_cache_warmup_stage_completed_count($selected, $index, 2),
        'stage1_failed'=>(int)$stage1['failed'],
        'stage1_deferred'=>(int)$stage1['deferred'],
        'stage2_failed'=>(int)$stage2['failed'],
        'stage2_deferred'=>(int)$stage2['deferred'],
      ));
      return 0;
    }

    bratonien_tools_cache_warmup_status($connection_id, 'complete', $mode === 'rebuild' ? 'Piwigo hat den vollständigen Shadow-Bestand für den Cache-Rebuild verarbeitet.' : 'Worker-Lauf vollständig beendet.', array(
      'mode'=>$mode,
      'shadow_sources'=>count($scan['sources']),
      'selected'=>count($selected),
      'stage1_completed'=>bratonien_tools_cache_warmup_stage_completed_count($selected, $index, 1),
      'stage2_completed'=>bratonien_tools_cache_warmup_stage_completed_count($selected, $index, 2),
      'stage1_failed'=>0,
      'stage1_deferred'=>0,
      'stage2_failed'=>0,
      'stage2_deferred'=>0,
    ));
    return 0;
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
  elseif (preg_match('/^--mode=(sync|periodic|manual|rebuild)$/', $arg, $m)) $mode = $m[1];
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
  fwrite(STDERR, '[BRAT-WORKER] fatal '.$e->getMessage()."\n");
  exit(1);
}
