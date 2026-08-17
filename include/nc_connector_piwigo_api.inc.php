<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_piwigo_api_request($api_key, $method)
{
  if (!function_exists('curl_init'))
  {
    throw new RuntimeException('cURL ist in PHP nicht verfuegbar. Der API-Key-Test kann nicht ausgefuehrt werden.');
  }

  $url = rtrim(get_absolute_root_url(true), '/').'/ws.php';
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(array(
      'method' => (string)$method,
      'format' => 'json',
    )),
    CURLOPT_HTTPHEADER => array(
      'X-PIWIGO-API: '.(string)$api_key,
      'Accept: application/json',
      'Content-Type: application/x-www-form-urlencoded',
    ),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_USERAGENT => 'Bratonien-Tools-NC-Connector/'.(function_exists('bratonien_tools_current_version') ? bratonien_tools_current_version() : 'dev'),
  ));

  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false || $errno !== 0)
  {
    throw new RuntimeException('Piwigo-API konnte nicht erreicht werden: '.$error);
  }
  if ($http_code < 200 || $http_code >= 300)
  {
    throw new RuntimeException('Piwigo-API antwortete mit HTTP '.$http_code.'.');
  }

  $decoded = json_decode((string)$body, true);
  if (!is_array($decoded))
  {
    throw new RuntimeException('Piwigo-API lieferte keine gueltige JSON-Antwort.');
  }
  if (($decoded['stat'] ?? '') !== 'ok')
  {
    $message = (string)($decoded['message'] ?? $decoded['err'] ?? 'API-Aufruf wurde abgelehnt.');
    throw new RuntimeException($message);
  }

  return $decoded['result'] ?? null;
}

function bratonien_tools_nc_connector_piwigo_api_test()
{
  $api_key = trim((string)($_POST['nc_piwigo_api_key'] ?? ''));
  if ($api_key === '')
  {
    throw new RuntimeException('Piwigo-API-Key fehlt.');
  }

  // Piwigo 16 API keys have a public pkid and a secret separated by a colon.
  // Do not reject other formats outright so the checker remains useful if
  // Piwigo changes the exact key syntax later.
  $status = bratonien_tools_nc_connector_piwigo_api_request($api_key, 'pwg.session.getStatus');
  if (!is_array($status))
  {
    throw new RuntimeException('Piwigo hat keinen auswertbaren Benutzerstatus geliefert.');
  }

  $username = (string)($status['username'] ?? $status['user'] ?? '');
  $user_status = strtolower((string)($status['status'] ?? ''));
  $is_admin = in_array($user_status, array('admin', 'webmaster'), true);

  $method_result = bratonien_tools_nc_connector_piwigo_api_request($api_key, 'reflection.getMethodList');
  $methods = array();
  if (is_array($method_result))
  {
    foreach ($method_result as $entry)
    {
      if (is_string($entry))
      {
        $methods[] = $entry;
      }
      elseif (is_array($entry) && isset($entry['name']))
      {
        $methods[] = (string)$entry['name'];
      }
    }
  }

  $sync_candidates = array_values(array_filter($methods, function($method)
  {
    return preg_match('/sync|synchron|site/i', (string)$method) === 1;
  }));
  sort($sync_candidates, SORT_STRING);

  $result = array(
    'ok' => true,
    'username' => $username !== '' ? $username : 'nicht gemeldet',
    'status' => $user_status !== '' ? $user_status : 'nicht gemeldet',
    'admin' => $is_admin,
    'method_count' => count($methods),
    'sync_candidates' => $sync_candidates,
    'sync_api_detected' => count($sync_candidates) > 0,
  );

  if (!$is_admin)
  {
    $result['conclusion'] = 'Der API-Key funktioniert, gehoert aber keinem Piwigo-Administrator/Webmaster. Fuer den heutigen NC-Connector-Sync reicht dieser Zugang nicht aus.';
  }
  elseif ($result['sync_api_detected'])
  {
    $result['conclusion'] = 'Der API-Key funktioniert mit Administratorrechten. Es wurden API-Methoden mit moeglichem Sync-/Site-Bezug gefunden. Diese muessen vor einer Runtime-Umstellung einzeln auf Eignung und Nebenwirkungen geprueft werden.';
  }
  else
  {
    $result['conclusion'] = 'Der API-Key funktioniert mit Administratorrechten. In der sichtbaren Web-API wurde jedoch keine offensichtliche Sync-/Site-Methode gefunden. Der bestehende Admin-Login wird deshalb noch nicht ersetzt.';
  }

  return array(
    'message' => 'Piwigo-API-Key wurde geprueft. Es wurden keine Zugangsdaten gespeichert und keine Synchronisation ausgeloest.',
    'nc_piwigo_api_test' => $result,
  );
}
