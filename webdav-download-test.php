<?php
define('PHPWG_ROOT_PATH', '../../');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

function bratonien_tools_webdav_download_test_abort($status, $message)
{
  http_response_code((int)$status);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store');
  echo $message;
  exit;
}

function bratonien_tools_webdav_download_test_decrypt_secret($blob, $hex_key)
{
  $hex_key = trim((string)$hex_key);
  if (!preg_match('/^[a-f0-9]{64}$/', $hex_key)) return null;

  $outer = base64_decode(trim((string)$blob), true);
  $payload = is_string($outer) ? json_decode($outer, true) : null;
  if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) return null;

  $iv = base64_decode((string)($payload['iv'] ?? ''), true);
  $tag = base64_decode((string)($payload['tag'] ?? ''), true);
  $cipher = base64_decode((string)($payload['data'] ?? ''), true);
  if (!is_string($iv) || !is_string($tag) || !is_string($cipher)) return null;

  $plain = openssl_decrypt($cipher, 'aes-256-gcm', hex2bin($hex_key), OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false) return null;

  $decoded = json_decode((string)$plain, true);
  return is_array($decoded) ? $decoded : null;
}

function bratonien_tools_webdav_download_test_quote_path($path)
{
  $parts = array_values(array_filter(explode('/', trim((string)$path, '/')), 'strlen'));
  return implode('/', array_map('rawurlencode', $parts));
}

function bratonien_tools_webdav_download_test_source_info($image_id, array $image_row)
{
  $path = (string)($image_row['path'] ?? '');
  if ($image_id < 1 || $path === '') return null;

  $absolute = $path;
  if (strpos($absolute, '/') !== 0)
  {
    $absolute = PHPWG_ROOT_PATH.ltrim(preg_replace('#^\\./#', '', $absolute), '/');
  }

  $resolved = realpath($absolute);
  if ($resolved === false) return null;

  $normalized = str_replace('\\', '/', $resolved);
  if (!preg_match('#/nc-webdav-source/connection-([0-9]+)/root-([0-9]+)/(.*)$#', $normalized, $match))
  {
    return null;
  }

  $connection_id = (int)$match[1];
  $root_fileid = (int)$match[2];
  $relative_path = trim((string)$match[3], '/');
  if ($connection_id < 1 || $root_fileid < 1 || $relative_path === '') return null;

  $table = $GLOBALS['prefixeTable'].'bratonien_tools_nc_connections';
  $result = pwg_query('SELECT config_json FROM `'.$table.'` WHERE id='.$connection_id.' LIMIT 1');
  if (!pwg_db_num_rows($result)) return null;

  $row = pwg_db_fetch_assoc($result);
  $config = json_decode((string)$row['config_json'], true);
  if (!is_array($config) || (string)($config['source_mode'] ?? '') !== 'webdav-placeholder') return null;

  $root_found = false;
  $root_path = '';
  foreach ((array)($config['roots'] ?? array()) as $root)
  {
    if ((int)($root['fileid'] ?? 0) === $root_fileid)
    {
      $root_found = true;
      $root_path = trim((string)($root['webdav_path'] ?? ''), '/');
      break;
    }
  }
  if (!$root_found) return null;

  $webdav_path = trim($root_path === '' ? $relative_path : $root_path.'/'.$relative_path, '/');
  if ($webdav_path === '') return null;

  $size = 0;
  $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
  if ($state_dir !== '')
  {
    $mapping_file = $state_dir.'/webdav-map.json';
    if (is_readable($mapping_file))
    {
      $mapping = json_decode((string)file_get_contents($mapping_file), true);
      if (is_array($mapping) && isset($mapping['files']) && is_array($mapping['files']))
      {
        $entry = $mapping['files'][$resolved] ?? $mapping['files'][$normalized] ?? null;
        if (is_array($entry) && (string)($entry['kind'] ?? '') === 'file')
        {
          $webdav_path = trim((string)($entry['webdav_path'] ?? $webdav_path), '/');
          $size = (int)($entry['size'] ?? 0);
        }
      }
    }
  }

  return array(
    'connection_id'=>$connection_id,
    'webdav_path'=>$webdav_path,
    'size'=>$size,
  );
}

function bratonien_tools_webdav_download_test_connection(array $source)
{
  $table = $GLOBALS['prefixeTable'].'bratonien_tools_nc_connections';
  $result = pwg_query('SELECT config_json, secret_blob FROM `'.$table.'` WHERE id='.(int)$source['connection_id'].' LIMIT 1');
  if (!pwg_db_num_rows($result)) return null;

  $row = pwg_db_fetch_assoc($result);
  $config = json_decode((string)$row['config_json'], true);
  if (!is_array($config)) return null;

  $key_result = pwg_query("SELECT value FROM ".$GLOBALS['prefixeTable']."config WHERE param='bratonien_nc_connector_secret' LIMIT 1");
  if (!pwg_db_num_rows($key_result)) return null;
  $key_row = pwg_db_fetch_assoc($key_result);

  $credentials = bratonien_tools_webdav_download_test_decrypt_secret((string)$row['secret_blob'], (string)$key_row['value']);
  if (!is_array($credentials)) return null;

  $base_url = rtrim((string)($config['nextcloud_url'] ?? ''), '/');
  $user = trim((string)($credentials['nextcloud_user'] ?? ''));
  $password = (string)($credentials['nextcloud_password'] ?? '');
  if ($base_url === '' || $user === '' || $password === '') return null;

  return array('base_url'=>$base_url, 'user'=>$user, 'password'=>$password);
}

global $user;
if (!isset($user['status']) || !in_array($user['status'], array('admin', 'webmaster'), true))
{
  bratonien_tools_webdav_download_test_abort(403, 'Dieser Test-Endpunkt ist nur fuer Administratoren verfuegbar.');
}

$image_id = (int)($_GET['id'] ?? 0);
$parallel = (int)($_GET['parallel'] ?? 1);
if ($image_id < 1)
{
  bratonien_tools_webdav_download_test_abort(400, 'Bild-ID fehlt.');
}
if ($parallel < 1 || $parallel > 20)
{
  bratonien_tools_webdav_download_test_abort(400, 'parallel muss zwischen 1 und 20 liegen.');
}

$result = pwg_query('SELECT * FROM '.IMAGES_TABLE.' WHERE id='.$image_id.' LIMIT 1');
if (!pwg_db_num_rows($result))
{
  bratonien_tools_webdav_download_test_abort(404, 'Bild nicht gefunden.');
}
$image_row = pwg_db_fetch_assoc($result);

$source = bratonien_tools_webdav_download_test_source_info($image_id, $image_row);
if (!$source)
{
  bratonien_tools_webdav_download_test_abort(404, 'Keine WebDAV-Quelle fuer dieses Bild gefunden.');
}

$connection = bratonien_tools_webdav_download_test_connection($source);
if (!$connection)
{
  bratonien_tools_webdav_download_test_abort(500, 'WebDAV-Verbindung konnte nicht geladen werden.');
}

$upload_dir = rtrim((string)($GLOBALS['conf']['upload_dir'] ?? './upload'), '/');
$test_rel_dir = $upload_dir.'/bratonien-webdav-download-test';
$test_root = PHPWG_ROOT_PATH.$test_rel_dir;
if (!is_dir($test_root) && !@mkdir($test_root, 0755, true))
{
  bratonien_tools_webdav_download_test_abort(500, 'Download-Testbereich konnte nicht angelegt werden: '.$test_root);
}
if (!is_writable($test_root))
{
  bratonien_tools_webdav_download_test_abort(500, 'Download-Testbereich ist fuer PHP nicht beschreibbar: '.$test_root);
}

$url = $connection['base_url'].'/remote.php/dav/files/'.rawurlencode($connection['user']).'/'.bratonien_tools_webdav_download_test_quote_path($source['webdav_path']);
$token = getmypid().'-'.bin2hex(random_bytes(6));
$multi = curl_multi_init();
$items = array();

for ($i = 0; $i < $parallel; $i++)
{
  $path = $test_root.'/download-'.$image_id.'-'.$token.'-'.$i.'.tmp';
  $fp = @fopen($path, 'xb');
  if (!$fp)
  {
    foreach ($items as $item)
    {
      @fclose($item['fp']);
      @unlink($item['path']);
      curl_multi_remove_handle($multi, $item['ch']);
      curl_close($item['ch']);
    }
    curl_multi_close($multi);
    bratonien_tools_webdav_download_test_abort(500, 'Temporaere Download-Datei konnte nicht angelegt werden.');
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $connection['user'].':'.$connection['password'],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FAILONERROR => false,
    CURLOPT_FILE => $fp,
    CURLOPT_USERAGENT => 'Bratonien-Tools-WebDAV-Download-Stress-Test',
  ));
  curl_multi_add_handle($multi, $ch);
  $items[] = array('ch'=>$ch, 'fp'=>$fp, 'path'=>$path);
}

$started_at = microtime(true);
do
{
  $status = curl_multi_exec($multi, $running);
  if ($status !== CURLM_OK) break;
  if ($running > 0)
  {
    $selected = curl_multi_select($multi, 1.0);
    if ($selected === -1) usleep(10000);
  }
}
while ($running > 0);
$elapsed_seconds = microtime(true) - $started_at;

$downloads = array();
$total_bytes = 0;
$successful = 0;
$failed = 0;

foreach ($items as $index => $item)
{
  @fflush($item['fp']);
  @fclose($item['fp']);

  $http = (int)curl_getinfo($item['ch'], CURLINFO_HTTP_CODE);
  $time_ms = round((float)curl_getinfo($item['ch'], CURLINFO_TOTAL_TIME) * 1000, 2);
  $errno = curl_errno($item['ch']);
  $error = curl_error($item['ch']);
  $bytes = is_file($item['path']) ? (int)filesize($item['path']) : 0;
  $ok = ($errno === 0 && $http >= 200 && $http < 300 && $bytes > 0);

  if ($ok) $successful++; else $failed++;
  $total_bytes += $bytes;

  $downloads[] = array(
    'index'=>$index + 1,
    'ok'=>$ok,
    'http'=>$http,
    'bytes'=>$bytes,
    'elapsed_ms'=>$time_ms,
    'mbps'=>$time_ms > 0 ? round(($bytes * 8) / ($time_ms / 1000) / 1000000, 2) : 0,
    'curl_errno'=>$errno,
    'curl_error'=>$error,
  );

  @unlink($item['path']);
  curl_multi_remove_handle($multi, $item['ch']);
  curl_close($item['ch']);
}
curl_multi_close($multi);

$elapsed_ms = round($elapsed_seconds * 1000, 2);
$aggregate_mbps = $elapsed_seconds > 0 ? round(($total_bytes * 8) / $elapsed_seconds / 1000000, 2) : 0;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(array(
  'image_id'=>$image_id,
  'parallel'=>$parallel,
  'same_source_repeated'=>true,
  'expected_source_bytes'=>(int)$source['size'],
  'successful'=>$successful,
  'failed'=>$failed,
  'total_bytes'=>$total_bytes,
  'peak_temp_disk_bytes'=>$total_bytes,
  'elapsed_ms'=>$elapsed_ms,
  'aggregate_mbps'=>$aggregate_mbps,
  'downloads'=>$downloads,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
