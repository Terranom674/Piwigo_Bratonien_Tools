<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_wizard_finish_generic_scope()
{
  $state = bratonien_tools_nc_wizard_state();
  if ((int)($state['step'] ?? 0) !== 4 || empty($state['technical_complete']) || trim((string)($state['username'] ?? '')) === '')
  {
    throw new RuntimeException('Der Assistent ist noch nicht vollständig.');
  }

  $roots = isset($state['roots']) && is_array($state['roots']) ? $state['roots'] : array();
  $storages = isset($state['storages']) && is_array($state['storages']) ? $state['storages'] : array();
  if (!$roots) throw new RuntimeException('Es wurden keine Nextcloud-Quellen ausgewählt.');
  if (!$storages) throw new RuntimeException('Es wurden keine Storage-Adapter zugeordnet.');

  if (array_key_exists('nc_wizard_fallback_user', $_POST)) $state['_fallback_user'] = trim((string)$_POST['nc_wizard_fallback_user']);
  if (array_key_exists('nc_wizard_fallback_password', $_POST)) $state['_fallback_password'] = (string)$_POST['nc_wizard_fallback_password'];
  bratonien_tools_nc_wizard_store($state);

  $fallback_user = trim((string)$state['_fallback_user']);
  $fallback_password = (string)$state['_fallback_password'];
  if (($fallback_user === '') !== ($fallback_password === '')) throw new RuntimeException('Fallback-Benutzer und Fallback-Passwort müssen entweder beide angegeben oder beide leer gelassen werden.');
  if (($state['api_status'] ?? '') !== 'ok' && $fallback_user === '') throw new RuntimeException('Da die Piwigo-API übersprungen wurde, ist für diese Verbindung ein Fallback-Zugang erforderlich.');

  $mapping_lines = array();
  foreach ($storages as $storage)
  {
    $storage_id = trim((string)($storage['storage_id'] ?? ''));
    $source_prefix = trim((string)($storage['source_prefix'] ?? ''), '/');
    $local_mount = rtrim(trim((string)($storage['local_mount'] ?? '')), '/');
    if ($storage_id === '' || $local_mount === '') throw new RuntimeException('Eine Storage-Zuordnung ist unvollständig.');
    $key = $storage_id.'|'.$source_prefix.'|'.$local_mount;
    $mapping_lines[$key] = $storage_id.' | '.$source_prefix.' | '.$local_mount;
  }

  $_POST['nc_name']=(string)$state['connection_name'];
  $_POST['nc_host']=(string)$state['db_host'];
  $_POST['nc_port']=(string)$state['db_port'];
  $_POST['nc_database']=(string)$state['db_database'];
  $_POST['nc_user']=(string)$state['db_user'];
  $_POST['nc_db_password']=(string)$state['_db_password'];
  $_POST['nc_source_view']='piwigo_connector_files';
  $_POST['nc_activity_view']='piwigo_connector_activity';
  $_POST['nc_gallery_root']=(string)$state['gallery_root'];
  $_POST['nc_storages']=implode("\n", array_values($mapping_lines));
  $_POST['nc_quiet_seconds']='120';
  $_POST['nc_max_wait_seconds']='900';
  $_POST['nc_full_sync_seconds']='86400';
  $_POST['nc_piwigo_user']=$fallback_user;
  $_POST['nc_piwigo_password']=$fallback_password;
  $_POST['nc_nextcloud_url']=(string)$state['base_url'];
  $_POST['nc_access_user']=(string)$state['username'];
  $_POST['nc_product']=(string)$state['product'];
  $_POST['nc_version']=(string)$state['version'];
  $_POST['nc_api_validated']=($state['api_status'] ?? '')==='ok'?'1':'0';
  $_POST['nc_connection_api_key_id']=($state['api_status'] ?? '')==='ok'?(string)$state['_api_key_id']:'';
  $_POST['nc_connection_api_key_secret']=($state['api_status'] ?? '')==='ok'?(string)$state['_api_key_secret']:'';

  $result = bratonien_tools_nc_connector_create_local_api_first();
  $connection_id = (int)($result['connection_id'] ?? 0);
  if ($connection_id < 1) throw new RuntimeException('Die neue Verbindung konnte nicht gespeichert werden.');

  $connection = bratonien_tools_nc_connector_connection($connection_id, false);
  if (!$connection) throw new RuntimeException('Die neue Verbindung konnte nach dem Anlegen nicht gelesen werden.');
  $config = is_array($connection['config'] ?? null) ? $connection['config'] : array();
  $config['source_mode'] = 'selected-fileids';
  $config['source_view'] = 'piwigo_connector_files';
  $config['activity_view'] = 'piwigo_connector_activity';
  $config['access_user'] = (string)$state['username'];
  $config['nextcloud_access_user'] = (string)$state['username'];
  $config['storages'] = array_values($storages);
  $config['roots'] = array_values(array_map(function($root) {
    return array(
      'fileid'=>(int)($root['fileid'] ?? 0),
      'display_name'=>(string)($root['display_name'] ?? ''),
      'webdav_path'=>(string)($root['webdav_path'] ?? ''),
    );
  }, $roots));
  unset($config['showcase_user']);

  foreach ($config['roots'] as $root)
  {
    if ((int)$root['fileid'] < 1 || trim((string)$root['display_name']) === '') throw new RuntimeException('Eine ausgewählte Nextcloud-Quelle ist unvollständig.');
  }

  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json)) throw new RuntimeException('Die Quellenkonfiguration konnte nicht serialisiert werden.');
  $table = bratonien_tools_nc_connector_table();
  pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($config_json)."' WHERE id=".$connection_id." LIMIT 1");

  unset($_SESSION['bratonien_nc_wizard']);
  unset($result['connection_id']);
  $result['message'] = 'Verbindung wurde mit generischer Nextcloud-Quellenauswahl angelegt. '.$result['message'];
  return $result;
}
