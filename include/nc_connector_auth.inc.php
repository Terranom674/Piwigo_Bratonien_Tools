<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_api_credentials()
{
  global $conf;

  $blob = isset($conf['bratonien_nc_piwigo_api']) ? trim((string)$conf['bratonien_nc_piwigo_api']) : '';
  if ($blob === '')
  {
    return array('key_id'=>'', 'key_secret'=>'');
  }

  try
  {
    $plain = bratonien_tools_nc_connector_decrypt_secret($blob);
    $decoded = json_decode($plain, true);
    if (!is_array($decoded))
    {
      return array('key_id'=>'', 'key_secret'=>'');
    }

    return array(
      'key_id' => (string)($decoded['key_id'] ?? ''),
      'key_secret' => (string)($decoded['key_secret'] ?? ''),
    );
  }
  catch (Throwable $e)
  {
    return array('key_id'=>'', 'key_secret'=>'');
  }
}

function bratonien_tools_nc_api_credentials_store($key_id, $key_secret)
{
  global $conf;

  $key_id = trim((string)$key_id);
  $key_secret = trim((string)$key_secret);
  if ($key_id === '' || $key_secret === '')
  {
    throw new RuntimeException('API-Schluessel-ID und Geheimnis muessen angegeben werden.');
  }

  $payload = json_encode(array(
    'v' => 1,
    'key_id' => $key_id,
    'key_secret' => $key_secret,
  ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($payload))
  {
    throw new RuntimeException('API-Zugangsdaten konnten nicht serialisiert werden.');
  }

  $blob = bratonien_tools_nc_connector_encrypt_secret($payload);
  conf_update_param('bratonien_nc_piwigo_api', $blob);
  $conf['bratonien_nc_piwigo_api'] = $blob;
}

function bratonien_tools_nc_connector_api_delete()
{
  global $conf;

  conf_update_param('bratonien_nc_piwigo_api', '');
  $conf['bratonien_nc_piwigo_api'] = '';

  return array('message'=>'Gespeicherte Piwigo-API-Zugangsdaten wurden geloescht.');
}

function bratonien_tools_nc_connector_auth_credentials($connection)
{
  $credentials = bratonien_tools_nc_connector_credentials_from_blob($connection['secret_blob'] ?? '');
  return array(
    'db_password' => (string)($credentials['db_password'] ?? ''),
    'piwigo_user' => (string)($credentials['piwigo_user'] ?? ''),
    'piwigo_password' => (string)($credentials['piwigo_password'] ?? ''),
  );
}

function bratonien_tools_nc_connector_fallback_save()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $username = trim((string)($_POST['nc_fallback_user'] ?? ''));
  $password = (string)($_POST['nc_fallback_password'] ?? '');
  if ($username === '' || $password === '')
  {
    throw new RuntimeException('Benutzername und Passwort fuer den Fallback muessen angegeben werden.');
  }

  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }

  $credentials = bratonien_tools_nc_connector_auth_credentials($connection);
  if ($credentials['db_password'] === '')
  {
    throw new RuntimeException('Das Datenbankpasswort der Verbindung fehlt.');
  }

  $blob = bratonien_tools_nc_connector_encrypt_credentials(
    $credentials['db_password'],
    $username,
    $password
  );
  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET secret_blob='".pwg_db_real_escape_string($blob)."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".$id." LIMIT 1");

  return array('message'=>'Piwigo-Benutzername und Passwort wurden verschluesselt als API-Fallback gespeichert.');
}

function bratonien_tools_nc_connector_fallback_delete()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }

  $credentials = bratonien_tools_nc_connector_auth_credentials($connection);
  if ($credentials['db_password'] === '')
  {
    throw new RuntimeException('Das Datenbankpasswort der Verbindung fehlt.');
  }

  $blob = bratonien_tools_nc_connector_encrypt_credentials($credentials['db_password'], '', '');
  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET secret_blob='".pwg_db_real_escape_string($blob)."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".$id." LIMIT 1");

  return array('message'=>'Gespeicherter Benutzername/Passwort-Fallback wurde geloescht.');
}

function bratonien_tools_nc_connector_fallback_http_request($url, array $fields, $cookie_file)
{
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($fields),
    CURLOPT_COOKIEJAR => $cookie_file,
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 900,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_USERAGENT => 'Bratonien-Tools-NC-Fallback/'.(function_exists('bratonien_tools_current_version') ? bratonien_tools_current_version() : 'dev'),
  ));
  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false || $errno !== 0)
  {
    throw new RuntimeException('Piwigo-Fallback konnte nicht ausgefuehrt werden: '.$error);
  }
  if ($http < 200 || $http >= 300)
  {
    throw new RuntimeException('Piwigo-Fallback antwortete mit HTTP '.$http.'.');
  }

  return (string)$body;
}

function bratonien_tools_nc_connector_fallback_once()
{
  if (!function_exists('curl_init'))
  {
    throw new RuntimeException('cURL ist in PHP nicht verfuegbar.');
  }

  $username = trim((string)($_POST['nc_fallback_user'] ?? ''));
  $password = (string)($_POST['nc_fallback_password'] ?? '');
  if ($username === '' || $password === '')
  {
    throw new RuntimeException('Benutzername und Passwort fuer den einmaligen Fallback muessen angegeben werden.');
  }

  $base = rtrim(get_absolute_root_url(true), '/');
  $cookie_file = tempnam(sys_get_temp_dir(), 'br-nc-fallback-');
  if ($cookie_file === false)
  {
    throw new RuntimeException('Temporare Fallback-Sitzung konnte nicht angelegt werden.');
  }

  try
  {
    $login_body = bratonien_tools_nc_connector_fallback_http_request(
      $base.'/ws.php?format=json',
      array(
        'method'=>'pwg.session.login',
        'username'=>$username,
        'password'=>$password,
      ),
      $cookie_file
    );
    $login = json_decode($login_body, true);
    if (!is_array($login) || ($login['stat'] ?? '') !== 'ok')
    {
      throw new RuntimeException('Piwigo-Anmeldung fuer den einmaligen Fallback wurde abgelehnt.');
    }

    bratonien_tools_nc_connector_fallback_http_request(
      $base.'/admin.php?page=site_update&site=1',
      array(
        'sync'=>'files',
        'display_info'=>1,
        'privacy_level'=>0,
        'sync_meta'=>1,
        'simulate'=>0,
        'subcats-included'=>1,
        'bratonien_connector'=>1,
        'submit'=>1,
      ),
      $cookie_file
    );

    $orphan_body = bratonien_tools_nc_connector_fallback_http_request(
      $base.'/ws.php?format=json',
      array(
        'method'=>'bratonien.nc.syncOrphans',
        'site_id'=>1,
        'simulate'=>0,
      ),
      $cookie_file
    );
    $orphan = json_decode($orphan_body, true);
    if (!is_array($orphan) || ($orphan['stat'] ?? '') !== 'ok')
    {
      throw new RuntimeException('Orphan-Synchronisierung im einmaligen Fallback wurde abgelehnt.');
    }
  }
  finally
  {
    @unlink($cookie_file);
  }

  return array('message'=>'Einmaliger Benutzername/Passwort-Fallback wurde ausgefuehrt. Die Zugangsdaten wurden nicht gespeichert.');
}
