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
  $add = function($name) use (&$fields)
  {
    if (!in_array($name, $fields, true)) $fields[] = $name;
  };

  if (stripos($message, 'Name') !== false && stripos($message, 'Datenbankname') === false) $add('connection_name');
  if (stripos($message, 'Datenbank-Server') !== false) $add('nc_host');
  if (stripos($message, 'Datenbank-Port') !== false) $add('nc_port');
  if (stripos($message, 'Datenbankname') !== false) $add('nc_database');
  if (stripos($message, 'Reader-Benutzer') !== false) $add('nc_user');
  if (stripos($message, 'Datenbankpasswort') !== false) $add('nc_db_password');
  if (stripos($message, 'Storage-ID') !== false) $add('nc_storage_id[]');
  if (stripos($message, 'lokaler Pfad') !== false || stripos($message, 'Speicherort') !== false) $add('nc_local_mount[]');
  if (stripos($message, 'Galerieordner') !== false) $add('nc_gallery_root');
  if (stripos($message, 'Datenbankansichten') !== false || stripos($message, 'Source-View') !== false) $add('nc_source_view');
  if (stripos($message, 'Datenbankansichten') !== false || stripos($message, 'Activity-View') !== false) $add('nc_activity_view');

  if (stripos($message, 'Nextcloud-Adresse') !== false || stripos($message, 'Unter dieser Adresse') !== false) $add('nc_nextcloud_url');
  if (stripos($message, 'Nextcloud-Benutzer') !== false || stripos($message, 'Benutzername oder Passwort') !== false || stripos($message, 'Benutzerzugang') !== false) $add('nc_nextcloud_user');
  if (stripos($message, 'Nextcloud-Passwort') !== false || stripos($message, 'Benutzername oder Passwort') !== false || stripos($message, 'Benutzerzugang') !== false) $add('nc_nextcloud_password');

  if (stripos($message, 'API-Schluessel-ID') !== false || stripos($message, 'API-Key') !== false) $add('nc_connection_api_key_id');
  if (stripos($message, 'API-Geheimnis') !== false || stripos($message, 'API-Key') !== false) $add('nc_connection_api_key_secret');

  bratonien_tools_nc_edit_json(422, array(
    'ok'=>false,
    'message'=>$message,
    'fields'=>$fields,
  ));
}
