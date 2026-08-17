<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Controlled handover preparation for verified NC Connector connections.
 *
 * This phase intentionally does not stop, disable or modify the legacy
 * piwigo-sync service. It only marks a verified Connector connection as ready
 * for a later controlled takeover. The marker can be rolled back at any time.
 */

function bratonien_tools_nc_connector_ensure_takeover_state()
{
  bratonien_tools_nc_connector_ensure_table();
  $table = bratonien_tools_nc_connector_table();
  pwg_query("ALTER TABLE `$table` MODIFY takeover_state enum('imported','verified','ready','active','disabled') NOT NULL DEFAULT 'imported'");
}

function bratonien_tools_nc_connector_prepare_takeover()
{
  $id = isset($_POST['connection_id']) ? (int)$_POST['connection_id'] : 0;
  $connection = bratonien_tools_nc_connector_connection($id, false);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }
  if ($connection['takeover_state'] !== 'verified')
  {
    throw new RuntimeException('Nur eine erfolgreich verifizierte Verbindung kann fuer die Uebergabe vorbereitet werden.');
  }

  $config = $connection['config'];
  $verification = isset($config['verification']) && is_array($config['verification'])
    ? $config['verification']
    : array();
  if (empty($verification['ok']))
  {
    throw new RuntimeException('Die gespeicherte Verifikation ist nicht erfolgreich. Bitte die Verbindung erneut pruefen.');
  }

  $config['takeover'] = array(
    'prepared_at' => date('Y-m-d H:i:s'),
    'legacy_sync_untouched' => true,
    'connector_enabled' => false,
    'first_run' => array(
      'status' => 'pending',
      'finished_at' => null,
      'detail' => '',
    ),
  );
  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json))
  {
    throw new RuntimeException('Uebergabestatus konnte nicht gespeichert werden.');
  }

  bratonien_tools_nc_connector_ensure_takeover_state();
  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET
    takeover_state = 'ready',
    enabled = 0,
    config_json = '".pwg_db_real_escape_string($config_json)."',
    updated = '".pwg_db_real_escape_string($now)."'
    WHERE id = ".(int)$connection['id']);

  return array(
    'message' => 'Die verifizierte Nextcloud-Verbindung ist jetzt fuer die kontrollierte Uebergabe vorbereitet. Der Connector wurde noch nicht aktiviert und der Legacy-Sync bleibt unveraendert Produktionsverbindung.',
  );
}

function bratonien_tools_nc_connector_cancel_takeover()
{
  $id = isset($_POST['connection_id']) ? (int)$_POST['connection_id'] : 0;
  $connection = bratonien_tools_nc_connector_connection($id, false);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }
  if ($connection['takeover_state'] !== 'ready')
  {
    throw new RuntimeException('Diese Verbindung befindet sich nicht in der Uebergabevorbereitung.');
  }

  $config = $connection['config'];
  if (isset($config['takeover']))
  {
    unset($config['takeover']);
  }
  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json))
  {
    throw new RuntimeException('Uebergabestatus konnte nicht zurueckgesetzt werden.');
  }

  bratonien_tools_nc_connector_ensure_takeover_state();
  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET
    takeover_state = 'verified',
    enabled = 0,
    config_json = '".pwg_db_real_escape_string($config_json)."',
    updated = '".pwg_db_real_escape_string($now)."'
    WHERE id = ".(int)$connection['id']);

  return array(
    'message' => 'Die Uebergabevorbereitung wurde zurueckgenommen. Die Verbindung bleibt verifiziert und deaktiviert; der Legacy-Sync bleibt unveraendert aktiv.',
  );
}

/**
 * Normalize the outcome of a Connector sync run during takeover.
 *
 * Both "changed" and "no_changes" are successful results. Only "error"
 * represents a failed run and may trigger rollback of a controlled handover.
 */
function bratonien_tools_nc_connector_takeover_result($status, $detail = '')
{
  $status = trim((string)$status);
  if (!in_array($status, array('changed', 'no_changes', 'error'), true))
  {
    throw new RuntimeException('Unbekannter Connector-Laufstatus: '.$status);
  }

  return array(
    'status' => $status,
    'success' => $status !== 'error',
    'changed' => $status === 'changed',
    'finished_at' => date('Y-m-d H:i:s'),
    'detail' => (string)$detail,
  );
}
