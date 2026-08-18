<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_create_local_api_first()
{
  $required = array(
    'nc_name' => 'Name',
    'nc_host' => 'PostgreSQL-Host',
    'nc_database' => 'Datenbank',
    'nc_user' => 'Reader-Benutzer',
    'nc_db_password' => 'Reader-Passwort',
    'nc_source_view' => 'Source-View',
    'nc_activity_view' => 'Activity-View',
    'nc_gallery_root' => 'Galerie-Pfad',
  );
  foreach ($required as $field => $label)
  {
    if (trim((string)($_POST[$field] ?? '')) === '') throw new RuntimeException($label.' fehlt.');
  }

  $fallback_user = trim((string)($_POST['nc_piwigo_user'] ?? ''));
  $fallback_password = (string)($_POST['nc_piwigo_password'] ?? '');
  if (($fallback_user === '') !== ($fallback_password === ''))
  {
    throw new RuntimeException('Fallback-Benutzer und Fallback-Passwort muessen entweder beide angegeben oder beide leer gelassen werden.');
  }

  $api = bratonien_tools_nc_api_credentials();
  $validated_pending_api = (string)($_POST['nc_api_validated'] ?? '') === '1';
  if (($api['key_id'] ?? '') === '' && $fallback_user === '' && !$validated_pending_api)
  {
    throw new RuntimeException('Es ist weder ein geprüfter Piwigo-API-Zugang noch ein Benutzername/Passwort-Fallback vorhanden.');
  }

  $port = (int)($_POST['nc_port'] ?? 5432);
  if ($port < 1 || $port > 65535) throw new RuntimeException('Ungueltiger PostgreSQL-Port.');

  $gallery_root = rtrim(trim((string)$_POST['nc_gallery_root']), '/');
  if (strpos($gallery_root, './') === 0)
  {
    $piwigo_root = realpath(PHPWG_ROOT_PATH);
    if ($piwigo_root === false) throw new RuntimeException('Piwigo-Stammverzeichnis konnte nicht aufgelöst werden.');
    $gallery_root = rtrim($piwigo_root, '/').'/'.ltrim(substr($gallery_root, 2), '/');
  }
  if ($gallery_root === '' || $gallery_root[0] !== '/') throw new RuntimeException('Der Galerie-Pfad muss ein absoluter Pfad sein.');

  $config = array(
    'host'=>trim((string)$_POST['nc_host']),
    'port'=>(string)$port,
    'database'=>trim((string)$_POST['nc_database']),
    'user'=>trim((string)$_POST['nc_user']),
    'source_view'=>trim((string)$_POST['nc_source_view']),
    'activity_view'=>trim((string)$_POST['nc_activity_view']),
    'gallery_root'=>$gallery_root,
    'state_dir'=>'','status_file'=>'',
    'quiet_seconds'=>max(0,(int)($_POST['nc_quiet_seconds'] ?? 120)),
    'max_wait_seconds'=>max(60,(int)($_POST['nc_max_wait_seconds'] ?? 900)),
    'full_sync_seconds'=>max(300,(int)($_POST['nc_full_sync_seconds'] ?? 86400)),
    'storages'=>bratonien_tools_nc_connector_parse_storages($_POST['nc_storages'] ?? ''),
    'origin'=>'native','piwigo_auth'=>'api-first',
  );
  foreach (array('nextcloud_url'=>'nc_nextcloud_url','showcase_user'=>'nc_showcase_user','nextcloud_access_user'=>'nc_access_user','nextcloud_product'=>'nc_product','nextcloud_version'=>'nc_version') as $config_key=>$post_key)
  {
    $value=trim((string)($_POST[$post_key] ?? '')); if($value!=='') $config[$config_key]=$value;
  }
  bratonien_tools_nc_connector_view_name($config['source_view']);
  bratonien_tools_nc_connector_view_name($config['activity_view']);

  $table=bratonien_tools_nc_connector_table(); bratonien_tools_nc_connector_ensure_table(); $now=date('Y-m-d H:i:s');
  $connection_key='local-'.bin2hex(random_bytes(12));
  $secret_blob=bratonien_tools_nc_connector_encrypt_credentials((string)$_POST['nc_db_password'],$fallback_user,$fallback_password);
  $config_json=json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(!is_string($config_json)) throw new RuntimeException('Connector-Konfiguration konnte nicht serialisiert werden.');

  pwg_query("INSERT INTO `$table` (connection_key, name, adapter, enabled, takeover_state, config_json, secret_blob, created, updated) VALUES ('".pwg_db_real_escape_string($connection_key)."','".pwg_db_real_escape_string(trim((string)$_POST['nc_name']))."','local',0,'disabled','".pwg_db_real_escape_string($config_json)."','".pwg_db_real_escape_string($secret_blob)."','".pwg_db_real_escape_string($now)."','".pwg_db_real_escape_string($now)."')");
  $id=(int)pwg_db_insert_id();
  if($id<1) throw new RuntimeException('Die Connector-Verbindung konnte nicht eindeutig angelegt werden.');
  $config['state_dir']='/var/lib/bratonien-tools/nc-connector/connection-'.$id;
  $config['status_file']=$config['state_dir'].'/connector-status.json';
  $config_json=json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(!is_string($config_json))
  {
    pwg_query("DELETE FROM `$table` WHERE id=".$id." LIMIT 1");
    throw new RuntimeException('Connector-Konfiguration konnte nach dem Anlegen nicht serialisiert werden.');
  }
  pwg_query("UPDATE `$table` SET config_json='".pwg_db_real_escape_string($config_json)."' WHERE id=".$id);

  return array(
    'connection_id'=>$id,
    'message'=>$fallback_user==='' ? 'Nextcloud-Verbindung wurde API-first ohne dauerhaft gespeicherten Benutzername/Passwort-Fallback angelegt. Bitte technisch pruefen und danach im LXC aktivieren.' : 'Nextcloud-Verbindung wurde API-first mit verschluesseltem Benutzername/Passwort-Fallback angelegt. Bitte technisch pruefen und danach im LXC aktivieren.'
  );
}
