<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_wizard_finish_connection_scoped()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)$state['step'] !== 4 || empty($state['technical_complete']) || trim((string)$state['showcase_user']) === '') throw new RuntimeException('Der Assistent ist noch nicht vollständig.');

  if (array_key_exists('nc_wizard_fallback_user', $_POST)) $state['_fallback_user'] = trim((string)$_POST['nc_wizard_fallback_user']);
  if (array_key_exists('nc_wizard_fallback_password', $_POST)) $state['_fallback_password'] = (string)$_POST['nc_wizard_fallback_password'];
  bratonien_tools_nc_wizard_store($state);

  $fallback_user = trim((string)$state['_fallback_user']);
  $fallback_password = (string)$state['_fallback_password'];
  if (($fallback_user === '') !== ($fallback_password === '')) throw new RuntimeException('Fallback-Benutzer und Fallback-Passwort müssen entweder beide angegeben oder beide leer gelassen werden.');
  if ($state['api_status'] !== 'ok' && $fallback_user === '') throw new RuntimeException('Da die Piwigo-API übersprungen wurde, ist für diese Verbindung ein Fallback-Zugang erforderlich.');

  $mapping_lines = array();
  foreach ((array)$state['storages'] as $storage)
  {
    $key = (string)$storage['storage_id'].'|'.trim((string)$storage['source_prefix'], '/').'|'.(string)$storage['local_mount'];
    $mapping_lines[$key] = (string)$storage['storage_id'].' | '.trim((string)$storage['source_prefix'], '/').' | '.(string)$storage['local_mount'];
  }

  $_POST['nc_name']=(string)$state['connection_name'];
  $_POST['nc_host']=(string)$state['db_host'];
  $_POST['nc_port']=(string)$state['db_port'];
  $_POST['nc_database']=(string)$state['db_database'];
  $_POST['nc_user']=(string)$state['db_user'];
  $_POST['nc_db_password']=(string)$state['_db_password'];
  $_POST['nc_source_view']=(string)$state['source_view'];
  $_POST['nc_activity_view']=(string)$state['activity_view'];
  $_POST['nc_gallery_root']=(string)$state['gallery_root'];
  $_POST['nc_storages']=implode("\n",array_values($mapping_lines));
  $_POST['nc_quiet_seconds']='120';
  $_POST['nc_max_wait_seconds']='900';
  $_POST['nc_full_sync_seconds']='86400';
  $_POST['nc_piwigo_user']=$fallback_user;
  $_POST['nc_piwigo_password']=$fallback_password;
  $_POST['nc_nextcloud_url']=(string)$state['base_url'];
  $_POST['nc_showcase_user']=(string)$state['showcase_user'];
  $_POST['nc_access_user']=(string)$state['username'];
  $_POST['nc_product']=(string)$state['product'];
  $_POST['nc_version']=(string)$state['version'];
  $_POST['nc_api_validated']=$state['api_status']==='ok'?'1':'0';
  $_POST['nc_connection_api_key_id']=$state['api_status']==='ok'?(string)$state['_api_key_id']:'';
  $_POST['nc_connection_api_key_secret']=$state['api_status']==='ok'?(string)$state['_api_key_secret']:'';

  $result = bratonien_tools_nc_connector_create_local_api_first();
  $connection_id = (int)($result['connection_id'] ?? 0);
  if ($connection_id < 1) throw new RuntimeException('Die neue Verbindung konnte nicht gespeichert werden.');

  $connection = bratonien_tools_nc_connector_connection($connection_id, false);
  if (!$connection) throw new RuntimeException('Die neue Verbindung konnte nach dem Anlegen nicht gelesen werden.');
  $config = $connection['config'];
  $config['storages'] = array_values($state['storages']);
  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json)) throw new RuntimeException('Die Verzeichnisauswahl konnte nicht serialisiert werden.');
  $table = bratonien_tools_nc_connector_table();
  pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($config_json)."' WHERE id=".$connection_id." LIMIT 1");

  unset($_SESSION['bratonien_nc_wizard']);
  unset($result['connection_id']);
  $result['message'] = 'Verbindung wurde vollständig mit eigener Authentifizierung angelegt. '.$result['message'];
  return $result;
}

function bratonien_tools_nc_connector_delete_any()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, false);
  if (!$connection) throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');

  $status_dir = rtrim(PHPWG_ROOT_PATH, '/').'/_data/bratonien-tools/nc-connector-status';
  if (!is_dir($status_dir) && !@mkdir($status_dir, 0755, true) && !is_dir($status_dir)) throw new RuntimeException('Der Connector-Statusordner konnte nicht angelegt werden.');

  $tombstone = $status_dir.'/deleted-'.$id;
  if (@file_put_contents($tombstone, date('c')."\n", LOCK_EX) === false) throw new RuntimeException('Die Verbindung konnte nicht sicher für die Laufzeit deaktiviert werden.');

  $public_status = $status_dir.'/connection-'.$id.'.json';
  if (is_file($public_status)) @unlink($public_status);

  $config = is_array($connection['config'] ?? null) ? $connection['config'] : array();
  $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
  if ($state_dir !== '' && strpos($state_dir, '/var/lib/bratonien-tools/nc-connector/connection-'.$id) === 0 && file_exists($state_dir))
  {
    bratonien_tools_nc_connector_remove_tree($state_dir);
  }

  $table = bratonien_tools_nc_connector_table();
  pwg_query("DELETE FROM `$table` WHERE id=".$id." LIMIT 1");
  return array('message'=>'Connector-Verbindung wurde gelöscht und für weitere Laufzeitabrufe gesperrt. Nextcloud- und Piwigo-Bilder blieben unverändert.');
}
