#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

function user_scope_fail($message)
{
  throw new RuntimeException($message);
}

function user_scope_path_has_segment($path, $segment)
{
  $parts = preg_split('#[/\\\\]+#', trim((string)$path, '/\\'));
  foreach ($parts as $part)
  {
    if ((string)$part === (string)$segment) return true;
  }
  return false;
}

function user_scope_candidate($mount, $prefix, $accessUser)
{
  $mount = rtrim((string)$mount, '/');
  $prefix = trim((string)$prefix, '/');
  if ($mount === '' || $mount[0] !== '/') return null;
  $root = $mount.($prefix !== '' ? '/'.$prefix : '');
  if (!is_dir($root) || !is_readable($root)) return null;
  $real = realpath($root);
  if ($real === false || !user_scope_path_has_segment($real, $accessUser)) return null;
  return array('local_mount'=>$mount, 'source_prefix'=>$prefix, 'root'=>$real);
}

function user_scope_storage(array $storage, $accessUser)
{
  $mount = rtrim(trim((string)($storage['local_mount'] ?? '')), '/');
  $prefix = trim((string)($storage['source_prefix'] ?? ''), '/');
  $include = trim((string)($storage['include_prefix'] ?? ''), '/');
  $candidates = array();

  $add = function($candidate) use (&$candidates) {
    if (!$candidate) return;
    $candidates[$candidate['root']] = $candidate;
  };

  $add(user_scope_candidate($mount, $prefix, $accessUser));
  if ($mount !== '')
  {
    $add(user_scope_candidate(dirname($mount).'/'.$accessUser, $prefix, $accessUser));
    $add(user_scope_candidate($mount.'/'.$accessUser, $prefix, $accessUser));
  }

  if ($prefix !== '')
  {
    $parts = explode('/', $prefix);
    if ($parts && (string)$parts[0] !== (string)$accessUser)
    {
      array_shift($parts);
      $rest = implode('/', $parts);
      $add(user_scope_candidate($mount.'/'.$accessUser, $rest, $accessUser));
    }
  }

  if (count($candidates) !== 1)
  {
    user_scope_fail('Der lokale Dateistamm für Nextcloud-Benutzer '.$accessUser.' konnte nicht eindeutig bestimmt werden. Die Verbindung wird aus Sicherheitsgründen nicht gestartet.');
  }

  $resolved = reset($candidates);
  return array(
    'storage_id'=>'user:'.$accessUser,
    'source_prefix'=>$resolved['source_prefix'],
    'local_mount'=>$resolved['local_mount'],
    'include_prefix'=>$include,
  );
}

function user_scope_write_status($piwigoRoot, $id, $message)
{
  $dir = rtrim($piwigoRoot, '/').'/_data/bratonien-tools/nc-connector-status';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $payload = array(
    'state'=>'error',
    'message'=>'Benutzerbezogene Datenquelle konnte nicht vorbereitet werden',
    'timestamp'=>time(),
    'auth_mode'=>'failed',
    'api'=>array('state'=>'not_run','message'=>''),
    'fallback'=>array('state'=>'not_run','message'=>''),
    'error_detail'=>(string)$message,
  );
  @file_put_contents($dir.'/connection-'.$id.'.json', json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n", LOCK_EX);
}

function user_scope_shell_value($value)
{
  return escapeshellarg((string)$value);
}

$pluginRoot = dirname(__DIR__);
$piwigoRoot = dirname($pluginRoot, 2);
$dbConfig = $piwigoRoot.'/local/config/database.inc.php';
$configDir = '/etc/bratonien-tools/nc-connector';

try
{
  if (!is_readable($dbConfig)) user_scope_fail('Piwigo-Datenbankkonfiguration ist nicht lesbar.');
  $conf = array();
  $prefixeTable = 'piwigo_';
  require $dbConfig;
  foreach (array('db_host','db_user','db_password','db_base') as $key)
  {
    if (!isset($conf[$key])) user_scope_fail('Piwigo-Datenbankkonfiguration ist unvollständig: '.$key);
  }

  $db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
  if ($db->connect_errno) user_scope_fail('Piwigo-Datenbank ist nicht erreichbar: '.$db->connect_error);
  $db->set_charset('utf8mb4');
  $table = $prefixeTable.'bratonien_tools_nc_connections';
  $rows = $db->query("SELECT id,adapter,config_json FROM `{$table}` ORDER BY id");
  if (!$rows) user_scope_fail('Connector-Verbindungen konnten nicht gelesen werden: '.$db->error);

  while ($row = $rows->fetch_assoc())
  {
    $id = (int)$row['id'];
    if ((string)$row['adapter'] !== 'local') continue;
    $config = json_decode((string)$row['config_json'], true);
    if (!is_array($config)) continue;
    if ((string)($config['origin'] ?? '') !== 'native') continue;

    $accessUser = trim((string)($config['nextcloud_access_user'] ?? $config['access_user'] ?? ''));
    if ($accessUser === '') continue;

    try
    {
      $storages = isset($config['storages']) && is_array($config['storages']) ? $config['storages'] : array();
      if (!$storages) user_scope_fail('Keine Speicherzuordnung für Benutzer '.$accessUser.' vorhanden.');

      $resolved = array();
      foreach ($storages as $storage)
      {
        $item = user_scope_storage((array)$storage, $accessUser);
        $key = $item['local_mount'].'|'.$item['source_prefix'].'|'.$item['include_prefix'];
        $resolved[$key] = $item;
      }
      $config['storages'] = array_values($resolved);
      $config['source_mode'] = 'user-filesystem';
      $config['access_user'] = $accessUser;

      $json = json_encode($config, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      if (!is_string($json)) user_scope_fail('Benutzerbezogene Connector-Konfiguration konnte nicht serialisiert werden.');
      $escaped = $db->real_escape_string($json);
      if (!$db->query("UPDATE `{$table}` SET config_json='{$escaped}' WHERE id={$id} LIMIT 1"))
      {
        user_scope_fail('Benutzerbezogene Connector-Konfiguration konnte nicht gespeichert werden: '.$db->error);
      }

      $base = $configDir.'/connection-'.$id;
      $storagePath = $base.'.storages.tsv';
      $configPath = $base.'.conf';
      if (!is_file($configPath)) continue;

      $lines = array('# storage_id<TAB>source_prefix<TAB>local_mount<TAB>include_prefix');
      foreach ($config['storages'] as $storage)
      {
        $lines[] = (string)$storage['storage_id']."\t".(string)$storage['source_prefix']."\t".(string)$storage['local_mount']."\t".(string)$storage['include_prefix'];
      }
      file_put_contents($storagePath, implode("\n", $lines)."\n", LOCK_EX);
      chmod($storagePath, 0600);

      $existing = file($configPath, FILE_IGNORE_NEW_LINES);
      if (!is_array($existing)) user_scope_fail('Runtime-Konfiguration konnte nicht gelesen werden.');
      $filtered = array_values(array_filter($existing, function($line) {
        return strpos($line, 'SOURCE_MODE=') !== 0 && strpos($line, 'ACCESS_USER=') !== 0;
      }));
      $filtered[] = 'SOURCE_MODE=user-filesystem';
      $filtered[] = 'ACCESS_USER='.user_scope_shell_value($accessUser);
      file_put_contents($configPath, implode("\n", $filtered)."\n", LOCK_EX);
      chmod($configPath, 0600);
    }
    catch (Throwable $e)
    {
      @unlink($configDir.'/connection-'.$id.'.conf');
      user_scope_write_status($piwigoRoot, $id, $e->getMessage());
      fwrite(STDERR, 'NC Connector #'.$id.': '.$e->getMessage()."\n");
    }
  }
  exit(0);
}
catch (Throwable $e)
{
  fwrite(STDERR, 'NC Connector User Scope: '.$e->getMessage()."\n");
  exit(1);
}
