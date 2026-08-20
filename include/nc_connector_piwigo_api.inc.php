<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_xml_value(SimpleXMLElement $node)
{
  $children = $node->children();
  if (count($children) === 0) return (string)$node;

  $result = array();
  foreach ($children as $name => $child)
  {
    $value = bratonien_tools_nc_connector_xml_value($child);
    if (array_key_exists($name, $result))
    {
      if (!is_array($result[$name]) || !array_is_list($result[$name])) $result[$name] = array($result[$name]);
      $result[$name][] = $value;
    }
    else $result[$name] = $value;
  }
  return $result;
}

function bratonien_tools_nc_connector_decode_api_response($body, $content_type = '')
{
  $decoded = json_decode((string)$body, true);
  if (is_array($decoded)) return $decoded;

  if (!function_exists('simplexml_load_string')) throw new RuntimeException('Piwigo-API lieferte XML, aber SimpleXML ist in PHP nicht verfügbar.');

  $previous = libxml_use_internal_errors(true);
  $xml = simplexml_load_string((string)$body);
  libxml_clear_errors();
  libxml_use_internal_errors($previous);
  if ($xml === false || $xml->getName() !== 'rsp')
  {
    $detail = $content_type !== '' ? ' Antworttyp: '.$content_type.'.' : '';
    throw new RuntimeException('Piwigo-API lieferte weder gültiges JSON noch eine gültige XML-Webservice-Antwort.'.$detail);
  }

  $response = array('stat'=>(string)$xml['stat']);
  foreach ($xml->children() as $name => $child)
  {
    $value = bratonien_tools_nc_connector_xml_value($child);
    if (array_key_exists($name, $response))
    {
      if (!is_array($response[$name]) || !array_is_list($response[$name])) $response[$name] = array($response[$name]);
      $response[$name][] = $value;
    }
    else $response[$name] = $value;
  }
  return $response;
}

function bratonien_tools_nc_connector_api_payload(array $decoded)
{
  if (array_key_exists('result', $decoded)) return $decoded['result'];
  unset($decoded['stat']);
  return $decoded;
}

function bratonien_tools_nc_connector_collect_method_names($value, array &$methods)
{
  if (is_string($value))
  {
    $value = trim($value);
    if ($value !== '' && preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+$/', $value)) $methods[$value] = true;
    return;
  }
  if (!is_array($value)) return;

  if (isset($value['name']) && is_string($value['name']))
  {
    $name = trim($value['name']);
    if ($name !== '') $methods[$name] = true;
  }
  foreach ($value as $entry) bratonien_tools_nc_connector_collect_method_names($entry, $methods);
}

function bratonien_tools_nc_connector_piwigo_api_request($api_key_id, $api_key_secret, $method)
{
  if (!function_exists('curl_init')) throw new RuntimeException('cURL ist in PHP nicht verfügbar. Der API-Key-Test kann nicht ausgeführt werden.');

  $api_key_id = trim((string)$api_key_id);
  $api_key_secret = trim((string)$api_key_secret);
  if ($api_key_id === '' || $api_key_secret === '') throw new RuntimeException('API-Schlüssel-ID und Geheimnis müssen angegeben werden.');

  $url = rtrim(get_absolute_root_url(true), '/').'/ws.php';
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>http_build_query(array('method'=>(string)$method,'format'=>'json')),
    CURLOPT_HTTPHEADER=>array(
      'X-PIWIGO-API: '.$api_key_id.':'.$api_key_secret,
      'Accept: application/json, text/xml;q=0.9',
      'Content-Type: application/x-www-form-urlencoded',
    ),
    CURLOPT_CONNECTTIMEOUT=>10,
    CURLOPT_TIMEOUT=>20,
    CURLOPT_FOLLOWLOCATION=>false,
    CURLOPT_USERAGENT=>'Bratonien-Tools-NC-WebDAV/'.(function_exists('bratonien_tools_current_version') ? bratonien_tools_current_version() : 'dev'),
  ));

  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $content_type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  if ($body === false || $errno !== 0) throw new RuntimeException('Piwigo-API konnte nicht erreicht werden: '.$error);
  if ($http_code < 200 || $http_code >= 300) throw new RuntimeException('Piwigo-API antwortete mit HTTP '.$http_code.'.');

  $decoded = bratonien_tools_nc_connector_decode_api_response((string)$body, $content_type);
  if (($decoded['stat'] ?? '') !== 'ok')
  {
    $message = (string)($decoded['message'] ?? $decoded['err'] ?? 'API-Aufruf wurde abgelehnt.');
    throw new RuntimeException($message);
  }
  return bratonien_tools_nc_connector_api_payload($decoded);
}

function bratonien_tools_nc_connector_validate_fallback_credentials($username, $password)
{
  if (!function_exists('curl_init')) throw new RuntimeException('cURL ist in PHP nicht verfügbar. Der Piwigo-Fallback kann nicht geprüft werden.');

  $username = trim((string)$username);
  $password = (string)$password;
  if ($username === '' || $password === '') throw new RuntimeException('Piwigo-Benutzername und Passwort müssen angegeben werden.');

  $cookie_file = tempnam(sys_get_temp_dir(), 'br-pwg-auth-');
  if ($cookie_file === false) throw new RuntimeException('Temporäre Piwigo-Sitzung konnte nicht angelegt werden.');

  try
  {
    $url = rtrim(get_absolute_root_url(true), '/').'/ws.php?format=json';
    $request = function(array $fields) use ($url, $cookie_file)
    {
      $ch = curl_init($url);
      curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query($fields),
        CURLOPT_COOKIEJAR=>$cookie_file,
        CURLOPT_COOKIEFILE=>$cookie_file,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_USERAGENT=>'Bratonien-Tools-NC-WebDAV/'.(function_exists('bratonien_tools_current_version') ? bratonien_tools_current_version() : 'dev'),
      ));
      $body = curl_exec($ch);
      $errno = curl_errno($ch);
      $error = curl_error($ch);
      $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
      curl_close($ch);
      if ($body === false || $errno !== 0) throw new RuntimeException('Piwigo-Fallback konnte nicht geprüft werden: '.$error);
      if ($http < 200 || $http >= 300) throw new RuntimeException('Piwigo-Fallback-Prüfung antwortete mit HTTP '.$http.'.');
      $decoded = bratonien_tools_nc_connector_decode_api_response((string)$body, $type);
      if (($decoded['stat'] ?? '') !== 'ok')
      {
        $message = trim((string)($decoded['message'] ?? $decoded['err'] ?? 'Piwigo hat die Anmeldung abgelehnt.'));
        throw new RuntimeException($message !== '' ? $message : 'Piwigo hat die Anmeldung abgelehnt.');
      }
      return bratonien_tools_nc_connector_api_payload($decoded);
    };

    $request(array('method'=>'pwg.session.login','username'=>$username,'password'=>$password));
    $status = $request(array('method'=>'pwg.session.getStatus'));
    if (!is_array($status)) throw new RuntimeException('Piwigo hat keinen auswertbaren Benutzerstatus geliefert.');
    $role = strtolower(trim((string)($status['status'] ?? '')));
    if (!in_array($role, array('admin','webmaster'), true)) throw new RuntimeException('Der Piwigo-Fallback funktioniert, gehört aber keinem Administrator/Webmaster.');
    return array('username'=>(string)($status['username'] ?? $username),'status'=>$role);
  }
  finally
  {
    @unlink($cookie_file);
  }
}
