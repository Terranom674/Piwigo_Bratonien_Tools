<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_wizard_apply_known_database_profile(array &$state)
{
  if (empty($state['scan_ok']) || trim((string)($state['base_url'] ?? '')) === '')
  {
    return false;
  }

  foreach (bratonien_tools_nc_connector_connections() as $candidate)
  {
    $config = isset($candidate['config']) && is_array($candidate['config']) ? $candidate['config'] : array();
    $candidate_url = trim((string)($config['nextcloud_url'] ?? ''));
    if ($candidate_url === '') continue;

    try
    {
      if (bratonien_tools_nc_wizard_normalize_url($candidate_url) !== (string)$state['base_url']) continue;
    }
    catch (Throwable $ignored)
    {
      continue;
    }

    $connection = bratonien_tools_nc_connector_connection((int)$candidate['id'], true);
    if (!$connection || !is_array($connection['config'] ?? null)) continue;

    $known = $connection['config'];
    $db_user = trim((string)($known['user'] ?? ''));
    if ($db_user === '') continue;

    $db_password = '';
    try
    {
      $plain = bratonien_tools_nc_connector_decrypt_secret($connection['secret_blob'] ?? '');
      $decoded = json_decode($plain, true);
      $db_password = is_array($decoded) && array_key_exists('db_password', $decoded)
        ? (string)$decoded['db_password']
        : (string)$plain;
    }
    catch (Throwable $ignored)
    {
      $db_password = '';
    }
    if ($db_password === '') continue;

    $state['db_host'] = trim((string)($known['host'] ?? $state['db_host']));
    $state['db_port'] = trim((string)($known['port'] ?? $state['db_port']));
    $state['db_database'] = trim((string)($known['database'] ?? $state['db_database']));
    $state['db_user'] = $db_user;
    $state['_db_password'] = $db_password;
    $state['db_password_set'] = true;
    $state['source_view'] = trim((string)($known['source_view'] ?? $state['source_view']));
    $state['activity_view'] = trim((string)($known['activity_view'] ?? $state['activity_view']));
    $state['gallery_root'] = trim((string)($known['gallery_root'] ?? $state['gallery_root']));
    $state['storages'] = isset($known['storages']) && is_array($known['storages']) ? $known['storages'] : array();
    $state['storage_candidates'] = $state['storages'];
    $state['technical_source'] = 'Gespeicherte Reader-Verbindung wird erneut geprüft';
    $state['technical_error'] = '';

    return true;
  }

  return false;
}

function bratonien_tools_nc_wizard_scan_with_known_database()
{
  $result = bratonien_tools_nc_wizard_scan();
  $state = bratonien_tools_nc_wizard_state();

  if (empty($state['scan_ok'])) return $result;

  if (!bratonien_tools_nc_wizard_apply_known_database_profile($state))
  {
    $state['db_user'] = '';
    $state['_db_password'] = '';
    $state['db_password_set'] = false;
    $state['technical_complete'] = false;
    $state['technical_stage'] = 'database_details';
    $state['technical_source'] = 'Keine bekannte Reader-Verbindung gefunden';
    $state['technical_error'] = 'Für diese Nextcloud ist noch keine bekannte Datenbank-Reader-Verbindung gespeichert. Die Nextcloud-Anmeldedaten werden nicht als PostgreSQL-Zugang verwendet.';
    bratonien_tools_nc_wizard_store($state);

    return array('message'=>'Nextcloud wurde gefunden. Für den Datenzugriff wird eine separate Reader-Verbindung benötigt.');
  }

  try
  {
    bratonien_tools_nc_wizard_finish_data_access($state);
  }
  catch (Throwable $e)
  {
    $state['technical_complete'] = false;
    $state['technical_stage'] = 'database_details';
    $state['technical_error'] = $e->getMessage();
  }

  bratonien_tools_nc_wizard_store($state);

  if ($state['technical_stage'] === 'database_details')
  {
    return array('message'=>'Nextcloud wurde gefunden. Die bekannte Reader-Verbindung konnte noch nicht vollständig bestätigt werden.');
  }
  if ($state['technical_stage'] === 'mounts')
  {
    return array('message'=>'Nextcloud und Datenzugriff wurden bestätigt. Ein Speicherort muss noch zugeordnet werden.');
  }

  return array('message'=>'Nextcloud und die gespeicherte Reader-Verbindung wurden erfolgreich bestätigt.');
}

function bratonien_tools_nc_wizard_save_technical_with_known_database()
{
  $state = bratonien_tools_nc_wizard_state();
  if (empty($state['scan_ok'])) throw new RuntimeException('Bitte zuerst Nextcloud erfolgreich scannen.');

  if (trim((string)($state['db_user'] ?? '')) === '' || (string)($state['_db_password'] ?? '') === '')
  {
    bratonien_tools_nc_wizard_apply_known_database_profile($state);
  }

  if (trim((string)($state['db_user'] ?? '')) === '' || (string)($state['_db_password'] ?? '') === '')
  {
    throw new RuntimeException('Für diese Nextcloud sind noch keine Datenbank-Reader-Zugangsdaten bekannt. Nextcloud-Benutzer und -Passwort werden dafür bewusst nicht verwendet.');
  }

  $state['db_host'] = trim((string)($_POST['nc_wizard_db_host'] ?? $state['db_host']));
  $state['db_port'] = (string)max(1, min(65535, (int)($_POST['nc_wizard_db_port'] ?? $state['db_port'])));
  $state['db_database'] = trim((string)($_POST['nc_wizard_db_database'] ?? $state['db_database']));
  $state['technical_stage'] = 'database_details';
  bratonien_tools_nc_wizard_store($state);

  if ($state['db_host'] === '' || $state['db_database'] === '') throw new RuntimeException('Die Adresse der Datenbank ist noch unvollständig.');

  try
  {
    bratonien_tools_nc_wizard_finish_data_access($state);
    bratonien_tools_nc_wizard_store($state);

    return array(
      'message'=>$state['technical_complete']
        ? 'Datenzugriff wurde erfolgreich geprüft.'
        : 'Datenzugriff funktioniert. Ein Speicherort muss noch bestätigt werden.'
    );
  }
  catch (Throwable $e)
  {
    $state['technical_error'] = $e->getMessage();
    $state['technical_stage'] = 'database_details';
    bratonien_tools_nc_wizard_store($state);
    throw $e;
  }
}
