<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_scoped_secret(array $connection)
{
  $config = is_array($connection['config'] ?? null) ? $connection['config'] : array();
  if ((string)($connection['adapter'] ?? '') !== 'remote' || (string)($config['source_mode'] ?? '') !== 'webdav-placeholder')
  {
    throw new RuntimeException('Die Verbindung ist keine WebDAV-Verbindung.');
  }

  $plain = bratonien_tools_nc_connector_decrypt_secret($connection['secret_blob'] ?? '');
  $decoded = json_decode($plain, true);
  if (!is_array($decoded))
  {
    throw new RuntimeException('WebDAV-Zugangsdaten haben ein unbekanntes Format.');
  }

  return array(
    'v'=>3,
    'piwigo_user'=>(string)($decoded['piwigo_user'] ?? ''),
    'piwigo_password'=>(string)($decoded['piwigo_password'] ?? ''),
    'api_key_id'=>(string)($decoded['api_key_id'] ?? ''),
    'api_key_secret'=>(string)($decoded['api_key_secret'] ?? ''),
    'nextcloud_user'=>(string)($decoded['nextcloud_user'] ?? ''),
    'nextcloud_password'=>(string)($decoded['nextcloud_password'] ?? ''),
  );
}

function bratonien_tools_nc_connector_store_scoped_secret($id, array $connection, array $credentials)
{
  $config = is_array($connection['config'] ?? null) ? $connection['config'] : array();
  if ((string)($connection['adapter'] ?? '') !== 'remote' || (string)($config['source_mode'] ?? '') !== 'webdav-placeholder')
  {
    throw new RuntimeException('Die Verbindung ist keine WebDAV-Verbindung.');
  }
  if (trim((string)($credentials['nextcloud_user'] ?? '')) === '' || (string)($credentials['nextcloud_password'] ?? '') === '')
  {
    throw new RuntimeException('Die Nextcloud-Zugangsdaten der WebDAV-Verbindung fehlen.');
  }

  $credentials['v'] = 3;
  unset($credentials['db_password']);
  $payload = json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($payload)) throw new RuntimeException('Verbindungszugangsdaten konnten nicht serialisiert werden.');
  $blob = bratonien_tools_nc_connector_encrypt_secret($payload);

  $config['piwigo_auth'] = 'connection-scoped';
  $config['api_enabled'] = trim((string)($credentials['api_key_id'] ?? '')) !== '' && trim((string)($credentials['api_key_secret'] ?? '')) !== '';
  $config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($config_json)) throw new RuntimeException('Connector-Konfiguration konnte nicht serialisiert werden.');

  $table = bratonien_tools_nc_connector_table();
  $now = date('Y-m-d H:i:s');
  pwg_query("UPDATE `$table` SET secret_blob='".pwg_db_real_escape_string($blob)."', config_json='".pwg_db_real_escape_string($config_json)."', updated='".pwg_db_real_escape_string($now)."' WHERE id=".(int)$id." LIMIT 1");
}

function bratonien_tools_nc_connector_fallback_save_scoped()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $username = trim((string)($_POST['nc_fallback_user'] ?? ''));
  $password = (string)($_POST['nc_fallback_password'] ?? '');
  if ($username === '' || $password === '') throw new RuntimeException('Benutzername und Passwort für den Fallback müssen angegeben werden.');

  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection) throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  bratonien_tools_nc_connector_validate_fallback_credentials($username, $password);

  $credentials = bratonien_tools_nc_connector_scoped_secret($connection);
  $credentials['piwigo_user'] = $username;
  $credentials['piwigo_password'] = $password;
  bratonien_tools_nc_connector_store_scoped_secret($id, $connection, $credentials);
  return array('message'=>'Piwigo-Benutzername und Passwort wurden als Fallback dieser WebDAV-Verbindung gespeichert.');
}

function bratonien_tools_nc_connector_fallback_delete_scoped()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, true);
  if (!$connection) throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');

  $credentials = bratonien_tools_nc_connector_scoped_secret($connection);
  $credentials['piwigo_user'] = '';
  $credentials['piwigo_password'] = '';
  bratonien_tools_nc_connector_store_scoped_secret($id, $connection, $credentials);
  return array('message'=>'Fallback dieser WebDAV-Verbindung wurde gelöscht. Ein vorhandener API-Zugang blieb unverändert.');
}
