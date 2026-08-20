<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_connector_table()
{
  if (function_exists('bratonien_tools_table'))
  {
    return bratonien_tools_table('nc_connections');
  }

  return $GLOBALS['prefixeTable'].'bratonien_tools_nc_connections';
}

function bratonien_tools_nc_connector_ensure_table()
{
  $table = bratonien_tools_nc_connector_table();
  pwg_query("CREATE TABLE IF NOT EXISTS `$table` (
    id int(11) NOT NULL AUTO_INCREMENT,
    connection_key varchar(64) NOT NULL,
    name varchar(255) NOT NULL,
    adapter enum('remote') NOT NULL DEFAULT 'remote',
    enabled tinyint(1) NOT NULL DEFAULT 1,
    config_json mediumtext NOT NULL,
    secret_blob mediumtext DEFAULT NULL,
    created datetime NOT NULL,
    updated datetime NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY connection_key (connection_key)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function bratonien_tools_nc_connector_secret_key()
{
  global $conf;

  $name = 'bratonien_nc_connector_secret';
  $hex = isset($conf[$name]) ? trim((string)$conf[$name]) : '';
  if (!preg_match('/^[a-f0-9]{64}$/', $hex))
  {
    $hex = bin2hex(random_bytes(32));
    if (function_exists('conf_update_param'))
    {
      conf_update_param($name, $hex);
      $conf[$name] = $hex;
    }
  }

  return hex2bin($hex);
}

function bratonien_tools_nc_connector_encrypt_secret($plain)
{
  $plain = (string)$plain;
  if ($plain === '') return '';
  if (!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL wird zum sicheren Speichern der Connector-Zugangsdaten benötigt.');

  $iv = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt($plain, 'aes-256-gcm', bratonien_tools_nc_connector_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
  if ($cipher === false) throw new RuntimeException('Connector-Zugangsdaten konnten nicht verschlüsselt werden.');

  return base64_encode(json_encode(array(
    'v'=>1,
    'iv'=>base64_encode($iv),
    'tag'=>base64_encode($tag),
    'data'=>base64_encode($cipher),
  )));
}

function bratonien_tools_nc_connector_decrypt_secret($blob)
{
  $blob = trim((string)$blob);
  if ($blob === '') return '';
  if (!function_exists('openssl_decrypt')) throw new RuntimeException('OpenSSL wird zum Lesen der Connector-Zugangsdaten benötigt.');

  $outer = base64_decode($blob, true);
  $payload = is_string($outer) ? json_decode($outer, true) : null;
  if (!is_array($payload) || (int)($payload['v'] ?? 0) !== 1) throw new RuntimeException('Gespeicherte Connector-Zugangsdaten haben ein unbekanntes Format.');

  $iv = base64_decode((string)($payload['iv'] ?? ''), true);
  $tag = base64_decode((string)($payload['tag'] ?? ''), true);
  $cipher = base64_decode((string)($payload['data'] ?? ''), true);
  if (!is_string($iv) || !is_string($tag) || !is_string($cipher)) throw new RuntimeException('Gespeicherte Connector-Zugangsdaten sind beschädigt.');

  $plain = openssl_decrypt($cipher, 'aes-256-gcm', bratonien_tools_nc_connector_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false) throw new RuntimeException('Connector-Zugangsdaten konnten nicht entschlüsselt werden.');
  return (string)$plain;
}

function bratonien_tools_nc_connector_is_webdav(array $row)
{
  $config = isset($row['config']) && is_array($row['config']) ? $row['config'] : array();
  return (string)($row['adapter'] ?? '') === 'remote' && (string)($config['source_mode'] ?? '') === 'webdav-placeholder';
}

function bratonien_tools_nc_connector_connections()
{
  bratonien_tools_nc_connector_ensure_table();
  $table = bratonien_tools_nc_connector_table();
  $result = pwg_query("SELECT id, connection_key, name, adapter, enabled, config_json, secret_blob, created, updated FROM `$table` WHERE adapter='remote' ORDER BY id");
  $connections = array();

  while ($row = pwg_db_fetch_assoc($result))
  {
    $config = json_decode((string)$row['config_json'], true);
    if (!is_array($config) || (string)($config['source_mode'] ?? '') !== 'webdav-placeholder') continue;

    $has_fallback = false;
    try
    {
      $plain = bratonien_tools_nc_connector_decrypt_secret($row['secret_blob'] ?? '');
      $credentials = json_decode($plain, true);
      if (is_array($credentials))
      {
        $has_fallback = trim((string)($credentials['piwigo_user'] ?? '')) !== '' && (string)($credentials['piwigo_password'] ?? '') !== '';
      }
    }
    catch (Throwable $e)
    {
      $has_fallback = false;
    }

    unset($row['secret_blob']);
    $row['id'] = (int)$row['id'];
    $row['enabled'] = (bool)$row['enabled'];
    $row['config'] = $config;
    $row['user'] = (string)($config['nextcloud_access_user'] ?? $config['access_user'] ?? '');
    $row['storage_count'] = isset($config['roots']) && is_array($config['roots']) ? count($config['roots']) : 0;
    $row['fallback_stored'] = $has_fallback;
    $connections[] = $row;
  }

  return $connections;
}

function bratonien_tools_nc_connector_connection($id, $with_secret = false)
{
  bratonien_tools_nc_connector_ensure_table();
  $table = bratonien_tools_nc_connector_table();
  $id = (int)$id;
  if ($id <= 0) return null;

  $columns = 'id, connection_key, name, adapter, enabled, config_json, created, updated';
  if ($with_secret) $columns .= ', secret_blob';
  $result = pwg_query("SELECT $columns FROM `$table` WHERE id = $id AND adapter='remote' LIMIT 1");
  if (!pwg_db_num_rows($result)) return null;

  $row = pwg_db_fetch_assoc($result);
  $row['id'] = (int)$row['id'];
  $row['enabled'] = (bool)$row['enabled'];
  $row['config'] = json_decode((string)$row['config_json'], true);
  if (!is_array($row['config']) || (string)($row['config']['source_mode'] ?? '') !== 'webdav-placeholder') return null;
  return $row;
}

function bratonien_tools_nc_connector_status()
{
  $connections = bratonien_tools_nc_connector_connections();
  $active_count = 0;
  foreach ($connections as $connection)
  {
    if (!empty($connection['enabled'])) $active_count++;
  }

  return array(
    'phase'=>'webdav',
    'connections'=>$connections,
    'connection_count'=>count($connections),
    'active_count'=>$active_count,
  );
}
