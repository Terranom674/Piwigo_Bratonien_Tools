#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$pluginRoot = dirname(__DIR__);
$piwigoRoot = dirname($pluginRoot, 2);
$dbConfig = $piwigoRoot.'/local/config/database.inc.php';
$configDir = '/etc/bratonien-tools/nc-connector';
$stateRoot = '/var/lib/bratonien-tools/nc-connector';

function cleanup_fail($message)
{
  fwrite(STDERR, $message."\n");
  exit(1);
}

function cleanup_tree($path)
{
  $path = rtrim((string)$path, '/');
  if ($path === '' || $path === '/' || !file_exists($path)) return;
  if (is_link($path) || is_file($path))
  {
    @unlink($path);
    return;
  }
  $items = @scandir($path);
  if (!is_array($items)) return;
  foreach ($items as $item)
  {
    if ($item === '.' || $item === '..') continue;
    cleanup_tree($path.'/'.$item);
  }
  @rmdir($path);
}

if (!is_readable($dbConfig)) cleanup_fail('Piwigo-Datenbankkonfiguration ist nicht lesbar.');
$conf = array();
$prefixeTable = 'piwigo_';
require $dbConfig;
foreach (array('db_host','db_user','db_password','db_base') as $key)
{
  if (!isset($conf[$key])) cleanup_fail('Piwigo-Datenbankkonfiguration ist unvollstaendig: '.$key);
}

$db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
if ($db->connect_errno) cleanup_fail('Piwigo-Datenbank ist nicht erreichbar: '.$db->connect_error);
$db->set_charset('utf8mb4');

$table = $prefixeTable.'bratonien_tools_nc_connections';
$escapedTable = $db->real_escape_string($table);
$exists = $db->query("SHOW TABLES LIKE '{$escapedTable}'");
if (!$exists) cleanup_fail('Connector-Tabelle konnte nicht geprüft werden: '.$db->error);
if ($exists->num_rows === 0) exit(0);

$activeIds = array();
$result = $db->query("SELECT id FROM `{$table}`");
if (!$result) cleanup_fail('Connector-Verbindungen konnten nicht gelesen werden: '.$db->error);
while ($row = $result->fetch_assoc())
{
  $activeIds[(int)$row['id']] = true;
}

if (!is_dir($configDir)) exit(0);
foreach (glob($configDir.'/connection-*.conf') ?: array() as $configPath)
{
  if (!preg_match('/connection-([0-9]+)\.conf$/', $configPath, $matches)) continue;
  $id = (int)$matches[1];
  if (isset($activeIds[$id])) continue;

  echo "NC Connector: entferne verwaiste Laufzeitdateien fuer Verbindung {$id}.\n";
  foreach (array('.conf','.db-password','.piwigo-password','.storages.tsv','.roots.tsv') as $suffix)
  {
    @unlink($configDir.'/connection-'.$id.$suffix);
  }
  cleanup_tree($stateRoot.'/connection-'.$id);

  $statusDir = rtrim($piwigoRoot, '/').'/_data/bratonien-tools/nc-connector-status';
  @unlink($statusDir.'/connection-'.$id.'.json');
  @unlink($statusDir.'/deleted-'.$id);
}

exit(0);
