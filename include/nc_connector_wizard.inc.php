<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_wizard_state()
{
  $state = isset($_SESSION['bratonien_nc_wizard']) && is_array($_SESSION['bratonien_nc_wizard']) ? $_SESSION['bratonien_nc_wizard'] : array();

  return array_merge(array(
    'step'=>1,
    'scan_ok'=>false,
    'base_url'=>'',
    'host_input'=>'',
    'username'=>'',
    'display_name'=>'',
    'version'=>'',
    'product'=>'Nextcloud',
    'connection_name'=>'',
    'scan_message'=>'',
    'source_mode'=>'webdav-placeholder',
    'transport'=>'webdav',
    'gallery_root'=>'',
    'roots'=>array(),
    'technical_stage'=>'mounts',
    'technical_source'=>'WebDAV',
    'technical_error'=>'',
    'technical_complete'=>false,
    'directory_selection_ready'=>false,
    'directory_path'=>'',
    'directory_parent'=>'',
    'directory_children'=>array(),
    'directory_current_fileid'=>0,
    'directory_fileids'=>array(),
    'directory_selected'=>array(),
    'directory_selected_fileids'=>array(),
    'api_status'=>'pending',
    'api_username'=>'',
    'api_error'=>'',
    '_password'=>'',
    '_api_key_id'=>'',
    '_api_key_secret'=>'',
    '_fallback_user'=>'',
    '_fallback_password'=>'',
  ), $state);
}

function bratonien_tools_nc_wizard_store(array $state)
{
  $_SESSION['bratonien_nc_wizard'] = $state;
}

function bratonien_tools_nc_wizard_reset()
{
  unset($_SESSION['bratonien_nc_wizard']);
  return array('message'=>'Verbindungsassistent wurde zurückgesetzt.');
}

function bratonien_tools_nc_wizard_normalize_url($host)
{
  $host = trim((string)$host);
  if ($host === '') throw new RuntimeException('Nextcloud-Adresse fehlt.');
  if (!preg_match('#^https?://#i', $host)) $host = 'https://'.$host;

  $parts = parse_url($host);
  if (!is_array($parts) || empty($parts['host'])) throw new RuntimeException('Die Nextcloud-Adresse ist ungültig.');

  $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
  if (!in_array($scheme, array('http','https'), true)) throw new RuntimeException('Nextcloud muss per HTTP oder HTTPS erreichbar sein.');

  $url = $scheme.'://'.$parts['host'];
  if (!empty($parts['port'])) $url .= ':'.(int)$parts['port'];
  if (!empty($parts['path']) && $parts['path'] !== '/') $url .= '/'.trim($parts['path'], '/');

  return rtrim($url, '/');
}

function bratonien_tools_nc_wizard_candidate_urls($host)
{
  $host = trim((string)$host);
  if ($host === '') throw new RuntimeException('Bitte die Adresse der Nextcloud angeben.');
  if (preg_match('#^https?://#i', $host)) return array(bratonien_tools_nc_wizard_normalize_url($host));

  return array(
    bratonien_tools_nc_wizard_normalize_url('https://'.$host),
    bratonien_tools_nc_wizard_normalize_url('http://'.$host),
  );
}

function bratonien_tools_nc_wizard_http($url, $username = '', $password = '', array $headers = array())
{
  if (!function_exists('curl_init')) throw new RuntimeException('Der Server kann Nextcloud derzeit nicht per HTTP prüfen.');

  $ch = curl_init($url);
  $options = array(
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_MAXREDIRS=>3,
    CURLOPT_CONNECTTIMEOUT=>8,
    CURLOPT_TIMEOUT=>15,
    CURLOPT_HTTPHEADER=>array_merge(array('Accept: application/json'), $headers),
    CURLOPT_USERAGENT=>'Bratonien-Tools-NC-WebDAV/'.(function_exists('bratonien_tools_current_version') ? bratonien_tools_current_version() : 'dev'),
  );

  if ($username !== '')
  {
    $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    $options[CURLOPT_USERPWD] = $username.':'.$password;
  }

  curl_setopt_array($ch, $options);
  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false || $errno !== 0) throw new RuntimeException('Verbindung fehlgeschlagen.');

  return array('status'=>$status, 'body'=>(string)$body);
}

function bratonien_tools_nc_wizard_ocs_data($body)
{
  $decoded = json_decode((string)$body, true);
  if (!is_array($decoded) || !isset($decoded['ocs']) || !is_array($decoded['ocs'])) throw new RuntimeException('Nextcloud hat keine gültige Antwort geliefert.');

  $meta = isset($decoded['ocs']['meta']) && is_array($decoded['ocs']['meta']) ? $decoded['ocs']['meta'] : array();
  $status = strtolower(trim((string)($meta['status'] ?? '')));
  $status_code = isset($meta['statuscode']) ? (int)$meta['statuscode'] : 0;

  if ($status !== 'ok' && !in_array($status_code, array(100,200), true))
  {
    $message = trim((string)($meta['message'] ?? ''));
    if ($message === '' || strtolower($message) === 'ok') $message = 'Nextcloud hat die Anfrage abgelehnt.';
    throw new RuntimeException($message);
  }

  return isset($decoded['ocs']['data']) && is_array($decoded['ocs']['data']) ? $decoded['ocs']['data'] : array();
}

function bratonien_tools_nc_wizard_api_test()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 3) throw new RuntimeException('Der API-Test ist in diesem Assistentenschritt nicht verfügbar.');

  $posted_id = trim((string)($_POST['nc_wizard_api_key_id'] ?? ''));
  $posted_secret = trim((string)($_POST['nc_wizard_api_key_secret'] ?? ''));
  if ($posted_id !== '') $state['_api_key_id'] = $posted_id;
  if ($posted_secret !== '') $state['_api_key_secret'] = $posted_secret;
  bratonien_tools_nc_wizard_store($state);

  $key_id = trim((string)$state['_api_key_id']);
  $secret = trim((string)$state['_api_key_secret']);
  if ($key_id === '' || $secret === '') throw new RuntimeException('API-Schlüssel-ID und API-Geheimnis fehlen.');

  try
  {
    $status = bratonien_tools_nc_connector_piwigo_api_request($key_id, $secret, 'pwg.session.getStatus');
    $user_status = strtolower((string)($status['status'] ?? ''));
    if (!in_array($user_status, array('admin','webmaster'), true)) throw new RuntimeException('Der API-Key gehört keinem Piwigo-Administrator/Webmaster.');

    $method_result = bratonien_tools_nc_connector_piwigo_api_request($key_id, $secret, 'reflection.getMethodList');
    $method_map = array();
    bratonien_tools_nc_connector_collect_method_names($method_result, $method_map);
    $missing = array_values(array_diff(array('bratonien.nc.syncProductive','bratonien.nc.syncOrphans'), array_keys($method_map)));
    if ($missing) throw new RuntimeException('Benötigte Bratonien-Sync-Methoden fehlen: '.implode(', ', $missing).'.');

    $state['_api_key_id'] = $key_id;
    $state['_api_key_secret'] = $secret;
    $state['api_status'] = 'ok';
    $state['api_username'] = (string)($status['username'] ?? $status['user'] ?? '');
    $state['api_error'] = '';
    $state['step'] = 4;
    bratonien_tools_nc_wizard_store($state);

    return array('message'=>'Piwigo-API erfolgreich geprüft. Gespeichert wird sie erst beim Abschluss des Assistenten.');
  }
  catch (Throwable $e)
  {
    $state['api_status'] = 'error';
    $state['api_error'] = $e->getMessage();
    bratonien_tools_nc_wizard_store($state);
    throw $e;
  }
}

function bratonien_tools_nc_wizard_api_skip()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 3) throw new RuntimeException('Die API kann in diesem Assistentenschritt nicht übersprungen werden.');

  $state['api_status'] = 'skipped';
  $state['api_error'] = '';
  $state['step'] = 4;
  bratonien_tools_nc_wizard_store($state);

  return array('message'=>'Piwigo-API wurde übersprungen. Für diese Verbindung ist deshalb ein Fallback-Zugang erforderlich.');
}

function bratonien_tools_nc_connector_update_name()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $name = trim((string)($_POST['connection_name'] ?? ''));
  if ($id < 1 || $name === '') throw new RuntimeException('Verbindung oder Name fehlt.');

  $connection = bratonien_tools_nc_connector_connection($id, false);
  if (!$connection) throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  if ((string)($connection['adapter'] ?? '') !== 'remote' || (string)($connection['config']['source_mode'] ?? '') !== 'webdav-placeholder')
  {
    throw new RuntimeException('Es können nur WebDAV-Verbindungen bearbeitet werden.');
  }

  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET name='".pwg_db_real_escape_string($name)."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".$id." LIMIT 1");

  return array('message'=>'Verbindungsname wurde aktualisiert.');
}
