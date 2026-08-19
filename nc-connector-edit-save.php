<?php
$piwigo_root = realpath(dirname(__DIR__, 2));
if ($piwigo_root === false)
{
  http_response_code(500);
  exit;
}
define('PHPWG_ROOT_PATH', rtrim($piwigo_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function bratonien_tools_nc_edit_json($status, array $payload)
{
  http_response_code((int)$status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function bratonien_tools_nc_edit_fail($message, array $fields = array(), $stage = '', $detail = '')
{
  $visible = trim((string)$message);
  if ($stage !== '') $visible = 'Bereich: '.$stage.' — '.$visible;
  if ($detail !== '') $visible .= ' Technische Ursache: '.$detail;

  bratonien_tools_nc_edit_json(422, array(
    'ok'=>false,
    'message'=>$visible,
    'stage'=>(string)$stage,
    'detail'=>(string)$detail,
    'fields'=>array_values(array_unique($fields)),
  ));
}

function bratonien_tools_nc_edit_probe($url, $username = '', $password = '', array $headers = array())
{
  if (!function_exists('curl_init'))
  {
    return array('ok'=>false, 'http'=>0, 'errno'=>-1, 'error'=>'cURL ist in PHP nicht verfügbar.', 'body'=>'');
  }

  $ch = curl_init($url);
  $options = array(
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_MAXREDIRS=>3,
    CURLOPT_CONNECTTIMEOUT=>8,
    CURLOPT_TIMEOUT=>15,
    CURLOPT_HTTPHEADER=>array_merge(array('Accept: application/json'), $headers),
    CURLOPT_USERAGENT=>'Bratonien-Tools-NC-Editor/'.(function_exists('bratonien_tools_current_version') ? bratonien_tools_current_version() : 'dev'),
  );
  if ($username !== '')
  {
    $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
    $options[CURLOPT_USERPWD] = $username.':'.$password;
  }
  curl_setopt_array($ch, $options);
  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  return array(
    'ok'=>$body !== false && $errno === 0,
    'http'=>$http,
    'errno'=>$errno,
    'error'=>$error,
    'body'=>$body === false ? '' : (string)$body,
  );
}

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  bratonien_tools_nc_edit_json(404, array('ok'=>false, 'message'=>'Bratonien Tools ist nicht aktiv.', 'fields'=>array()));
}
if (!function_exists('is_admin') || !is_admin())
{
  bratonien_tools_nc_edit_json(403, array('ok'=>false, 'message'=>'Administratorrechte erforderlich.', 'fields'=>array()));
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
{
  bratonien_tools_nc_edit_json(405, array('ok'=>false, 'message'=>'Nur POST ist erlaubt.', 'fields'=>array()));
}

try
{
  check_pwg_token();
  require_once(BRATONIEN_TOOLS_PATH.'include/tool_registry.inc.php');

  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection)
  {
    bratonien_tools_nc_edit_fail('Die zu bearbeitende Verbindung wurde nicht gefunden.', array(), 'Verbindung');
  }

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $credentials = bratonien_tools_nc_connector_scoped_secret($connection);

  $nextcloud_input = trim((string)($_POST['nc_nextcloud_url'] ?? ($config['nextcloud_url'] ?? '')));
  $nextcloud_user = trim((string)($_POST['nc_nextcloud_user'] ?? ($credentials['nextcloud_user'] ?? '')));
  $submitted_nc_password = (string)($_POST['nc_nextcloud_password'] ?? '');
  $nextcloud_password = $submitted_nc_password !== '' ? $submitted_nc_password : (string)($credentials['nextcloud_password'] ?? '');

  $has_any_nextcloud = $nextcloud_input !== '' || $nextcloud_user !== '' || $nextcloud_password !== '';
  if ($has_any_nextcloud)
  {
    $missing = array();
    if ($nextcloud_input === '') $missing[] = 'nc_nextcloud_url';
    if ($nextcloud_user === '') $missing[] = 'nc_nextcloud_user';
    if ($nextcloud_password === '') $missing[] = 'nc_nextcloud_password';
    if ($missing)
    {
      bratonien_tools_nc_edit_fail(
        'Die WebDAV-Daten sind unvollständig. Adresse, Benutzer und Passwort werden gemeinsam benötigt.',
        $missing,
        'Nextcloud / WebDAV'
      );
    }

    try
    {
      $candidate_urls = bratonien_tools_nc_wizard_candidate_urls($nextcloud_input);
    }
    catch (Throwable $e)
    {
      bratonien_tools_nc_edit_fail($e->getMessage(), array('nc_nextcloud_url'), 'Nextcloud / WebDAV');
    }

    $nextcloud_url = '';
    $last_probe = null;
    $last_status_url = '';
    foreach ($candidate_urls as $candidate_url)
    {
      $status_url = $candidate_url.'/status.php';
      $probe = bratonien_tools_nc_edit_probe($status_url);
      $last_probe = $probe;
      $last_status_url = $status_url;

      if (!$probe['ok'] || $probe['http'] < 200 || $probe['http'] >= 300)
      {
        continue;
      }

      $status = json_decode($probe['body'], true);
      if (!is_array($status) || empty($status['installed']))
      {
        continue;
      }

      $nextcloud_url = $candidate_url;
      break;
    }

    if ($nextcloud_url === '')
    {
      $attempts = array();
      foreach ($candidate_urls as $candidate_url)
      {
        $attempts[] = $candidate_url.'/status.php';
      }
      if (is_array($last_probe) && !$last_probe['ok'])
      {
        $technical = $last_probe['errno'] >= 0
          ? 'cURL '.$last_probe['errno'].($last_probe['error'] !== '' ? ': '.$last_probe['error'] : '')
          : $last_probe['error'];
        bratonien_tools_nc_edit_fail(
          'Die Nextcloud-Adresse ist vom Piwigo-Server aus nicht erreichbar. Ohne angegebenes Protokoll wurden HTTPS und HTTP geprüft.',
          array('nc_nextcloud_url'),
          'Nextcloud / WebDAV – Erreichbarkeit',
          $technical.' · Geprüft: '.implode(' ; ', $attempts)
        );
      }

      bratonien_tools_nc_edit_fail(
        'Unter der angegebenen Adresse wurde keine erreichbare installierte Nextcloud gefunden. Ohne angegebenes Protokoll wurden HTTPS und HTTP geprüft.',
        array('nc_nextcloud_url'),
        'Nextcloud / WebDAV – Erkennung',
        'Geprüft: '.implode(' ; ', $attempts).($last_probe ? ' · Letzter HTTP-Status: '.$last_probe['http'] : '')
      );
    }

    $_POST['nc_nextcloud_url'] = $nextcloud_url;

    $user_url = $nextcloud_url.'/ocs/v2.php/cloud/user?format=json';
    $user_probe = bratonien_tools_nc_edit_probe($user_url, $nextcloud_user, $nextcloud_password, array('OCS-APIRequest: true'));
    if (!$user_probe['ok'])
    {
      $technical = $user_probe['errno'] >= 0
        ? 'cURL '.$user_probe['errno'].($user_probe['error'] !== '' ? ': '.$user_probe['error'] : '')
        : $user_probe['error'];
      bratonien_tools_nc_edit_fail(
        'Die Nextcloud-Anmeldung konnte technisch nicht geprüft werden.',
        array('nc_nextcloud_url','nc_nextcloud_user','nc_nextcloud_password'),
        'Nextcloud / WebDAV – Anmeldung',
        $technical.' · Ziel: '.$user_url
      );
    }
    if ($user_probe['http'] === 401 || $user_probe['http'] === 403)
    {
      bratonien_tools_nc_edit_fail(
        'Nextcloud hat Benutzername oder Passwort abgelehnt.',
        array('nc_nextcloud_user','nc_nextcloud_password'),
        'Nextcloud / WebDAV – Anmeldung',
        'HTTP '.$user_probe['http']
      );
    }
    if ($user_probe['http'] < 200 || $user_probe['http'] >= 300)
    {
      bratonien_tools_nc_edit_fail(
        'Nextcloud ist erreichbar, aber die Benutzerprüfung wurde vom Server abgelehnt.',
        array('nc_nextcloud_user','nc_nextcloud_password'),
        'Nextcloud / WebDAV – Anmeldung',
        'HTTP '.$user_probe['http'].' · Ziel: '.$user_url
      );
    }
    try
    {
      bratonien_tools_nc_wizard_ocs_data($user_probe['body']);
    }
    catch (Throwable $e)
    {
      bratonien_tools_nc_edit_fail(
        'Nextcloud antwortet auf die Benutzerprüfung, die Antwort ist aber nicht gültig: '.$e->getMessage(),
        array('nc_nextcloud_user','nc_nextcloud_password'),
        'Nextcloud / WebDAV – Anmeldung'
      );
    }
  }

  $api_key_id = trim((string)($_POST['nc_connection_api_key_id'] ?? ($credentials['api_key_id'] ?? '')));
  $submitted_api_secret = trim((string)($_POST['nc_connection_api_key_secret'] ?? ''));
  $api_key_secret = $submitted_api_secret !== '' ? $submitted_api_secret : trim((string)($credentials['api_key_secret'] ?? ''));
  if (($api_key_id === '') !== ($api_key_secret === ''))
  {
    $missing = $api_key_id === '' ? array('nc_connection_api_key_id') : array('nc_connection_api_key_secret');
    bratonien_tools_nc_edit_fail(
      'API-Schlüssel-ID und API-Geheimnis müssen gemeinsam vollständig sein.',
      $missing,
      'Piwigo API'
    );
  }
  if ($api_key_id !== '')
  {
    try
    {
      bratonien_tools_nc_connector_validate_scoped_api($api_key_id, $api_key_secret);
    }
    catch (Throwable $e)
    {
      bratonien_tools_nc_edit_fail(
        'Der Piwigo-API-Zugang konnte nicht bestätigt werden: '.$e->getMessage(),
        array('nc_connection_api_key_id','nc_connection_api_key_secret'),
        'Piwigo API'
      );
    }
  }

  $result = bratonien_tools_nc_connector_update_local_friendly();
  bratonien_tools_nc_edit_json(200, array(
    'ok'=>true,
    'message'=>(string)($result['message'] ?? 'Verbindung wurde gespeichert.'),
    'fields'=>array(),
  ));
}
catch (Throwable $e)
{
  $message = trim($e->getMessage());
  if ($message === '') $message = 'Die Verbindung konnte nicht gespeichert werden.';

  $fields = array();
  $stage = 'Verbindung';
  $add = function($name) use (&$fields)
  {
    if (!in_array($name, $fields, true)) $fields[] = $name;
  };

  if (stripos($message, 'Name') !== false && stripos($message, 'Datenbankname') === false) $add('connection_name');
  if (stripos($message, 'Datenbank-Server') !== false) { $add('nc_host'); $stage = 'Legacy-Datenbank'; }
  if (stripos($message, 'Datenbank-Port') !== false) { $add('nc_port'); $stage = 'Legacy-Datenbank'; }
  if (stripos($message, 'Datenbankname') !== false) { $add('nc_database'); $stage = 'Legacy-Datenbank'; }
  if (stripos($message, 'Reader-Benutzer') !== false) { $add('nc_user'); $stage = 'Legacy-Datenbank'; }
  if (stripos($message, 'Datenbankpasswort') !== false) { $add('nc_db_password'); $stage = 'Legacy-Datenbank'; }
  if (stripos($message, 'Storage-ID') !== false) { $add('nc_storage_id[]'); $stage = 'Speicherorte'; }
  if (stripos($message, 'lokaler Pfad') !== false || stripos($message, 'Speicherort') !== false) { $add('nc_local_mount[]'); $stage = 'Speicherorte'; }
  if (stripos($message, 'Galerieordner') !== false) { $add('nc_gallery_root'); $stage = 'Erweiterte Legacy-Einstellungen'; }
  if (stripos($message, 'Datenbankansichten') !== false || stripos($message, 'Source-View') !== false) { $add('nc_source_view'); $stage = 'Erweiterte Legacy-Einstellungen'; }
  if (stripos($message, 'Datenbankansichten') !== false || stripos($message, 'Activity-View') !== false) { $add('nc_activity_view'); $stage = 'Erweiterte Legacy-Einstellungen'; }

  if (stripos($message, 'Nextcloud') !== false)
  {
    $stage = 'Nextcloud / WebDAV';
    if (stripos($message, 'Adresse') !== false) $add('nc_nextcloud_url');
    if (stripos($message, 'Benutzer') !== false) $add('nc_nextcloud_user');
    if (stripos($message, 'Passwort') !== false) $add('nc_nextcloud_password');
  }
  if (stripos($message, 'API') !== false)
  {
    $stage = 'Piwigo API';
    $add('nc_connection_api_key_id');
    $add('nc_connection_api_key_secret');
  }

  bratonien_tools_nc_edit_fail(
    $message,
    $fields,
    $stage,
    'Interne Prüfung: '.get_class($e)
  );
}
