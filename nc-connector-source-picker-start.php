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

function bratonien_tools_nc_source_picker_json($status, array $payload)
{
  http_response_code((int)$status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function bratonien_tools_nc_source_picker_fail($message, array $fields = array())
{
  bratonien_tools_nc_source_picker_json(422, array(
    'ok'=>false,
    'message'=>(string)$message,
    'fields'=>array_values(array_unique($fields)),
  ));
}

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  bratonien_tools_nc_source_picker_json(404, array('ok'=>false, 'message'=>'Bratonien Tools ist nicht aktiv.', 'fields'=>array()));
}
if (!function_exists('is_admin') || !is_admin())
{
  bratonien_tools_nc_source_picker_json(403, array('ok'=>false, 'message'=>'Administratorrechte erforderlich.', 'fields'=>array()));
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
{
  bratonien_tools_nc_source_picker_json(405, array('ok'=>false, 'message'=>'Nur POST ist erlaubt.', 'fields'=>array()));
}

try
{
  check_pwg_token();
  require_once(BRATONIEN_TOOLS_PATH.'include/tool_registry.inc.php');

  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection)
  {
    bratonien_tools_nc_source_picker_fail('Die zu bearbeitende Verbindung wurde nicht gefunden.');
  }
  if ((string)$connection['adapter'] !== 'local')
  {
    bratonien_tools_nc_source_picker_fail('Die automatische Migrationsauswahl ist nur für die bestehende Legacy-Verbindung vorgesehen.');
  }

  $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
  $credentials = bratonien_tools_nc_connector_scoped_secret($connection);

  $base_url = trim((string)($config['nextcloud_url'] ?? ''));
  $username = trim((string)($credentials['nextcloud_user'] ?? ''));
  $password = (string)($credentials['nextcloud_password'] ?? '');

  $missing = array();
  if ($base_url === '') $missing[] = 'nc_nextcloud_url';
  if ($username === '') $missing[] = 'nc_nextcloud_user';
  if ($password === '') $missing[] = 'nc_nextcloud_password';
  if ($missing)
  {
    bratonien_tools_nc_source_picker_fail(
      'Für die automatische Ordnerauswahl müssen Nextcloud-Adresse, Benutzer und Passwort gespeichert sein.',
      $missing
    );
  }

  bratonien_tools_nc_connector_prepare_webdav_wizard_from_connection($connection, 'migrate');

  $state = bratonien_tools_nc_wizard_state();
  if ((int)($state['step'] ?? 0) !== 2 || empty($state['scan_ok']) || empty($state['directory_selection_ready']))
  {
    $detail = trim((string)($state['technical_error'] ?? ''));
    bratonien_tools_nc_source_picker_fail(
      $detail !== '' ? 'Die Nextcloud-Ordner konnten nicht geladen werden: '.$detail : 'Die Nextcloud-Ordner konnten nicht geladen werden.',
      array('nc_nextcloud_url','nc_nextcloud_user','nc_nextcloud_password')
    );
  }

  bratonien_tools_nc_source_picker_json(200, array(
    'ok'=>true,
    'message'=>'Nextcloud-Ordner wurden geladen. Die Auswahl wird geöffnet.',
    'fields'=>array(),
  ));
}
catch (Throwable $e)
{
  $message = trim($e->getMessage());
  if ($message === '') $message = 'Die Nextcloud-Ordnerauswahl konnte nicht vorbereitet werden.';
  bratonien_tools_nc_source_picker_fail(
    $message,
    array('nc_nextcloud_url','nc_nextcloud_user','nc_nextcloud_password')
  );
}
