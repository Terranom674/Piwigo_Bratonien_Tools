#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "Dieses Hilfsprogramm darf nur auf der Kommandozeile ausgefuehrt werden.\n");
  exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0)
{
  fwrite(STDERR, "Bitte als root ausfuehren.\n");
  exit(1);
}
if ($argc !== 2 || !preg_match('/^[1-9][0-9]*$/', $argv[1]))
{
  fwrite(STDERR, "Aufruf: php nc-connector-disable.php <connection-id>\n");
  exit(1);
}

$id = (int)$argv[1];
$piwigoRoot = dirname(__DIR__, 2);
$dbConfig = $piwigoRoot.'/local/config/database.inc.php';
$configDir = '/etc/bratonien-tools/nc-connector';

try
{
  $conf = array();
  $prefixeTable = 'piwigo_';
  if (!is_readable($dbConfig)) throw new RuntimeException('Piwigo-Datenbankkonfiguration nicht lesbar.');
  require $dbConfig;
  $db = new mysqli($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
  if ($db->connect_errno) throw new RuntimeException('Piwigo-Datenbank nicht erreichbar: '.$db->connect_error);
  $db->set_charset('utf8mb4');
  $table = $prefixeTable.'bratonien_tools_nc_connections';
  $result = $db->query("SELECT id, enabled, takeover_state, config_json FROM `".$table."` WHERE id=".$id." LIMIT 1");
  if (!$result || !$result->num_rows) throw new RuntimeException('Connector-Verbindung #'.$id.' wurde nicht gefunden.');
  $row = $result->fetch_assoc();
  if ((int)$row['enabled'] !== 1 || (string)$row['takeover_state'] !== 'active')
  {
    throw new RuntimeException('Die Verbindung ist nicht aktiv.');
  }

  passthru('systemctl stop bratonien-nc-connector.timer', $stopExit);
  if ($stopExit !== 0) throw new RuntimeException('Connector-Timer konnte nicht gestoppt werden.');

  $base = $configDir.'/connection-'.$id;
  foreach (array('.conf','.storages.tsv','.db-password','.piwigo-password') as $suffix)
  {
    $path = $base.$suffix;
    if (is_file($path) && !unlink($path)) throw new RuntimeException('Datei konnte nicht entfernt werden: '.$path);
  }

  $config = json_decode((string)$row['config_json'], true);
  if (!is_array($config)) $config = array();
  unset($config['runtime']);
  $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $now = date('Y-m-d H:i:s');
  $jsonEsc = $db->real_escape_string((string)$json);
  $nowEsc = $db->real_escape_string($now);
  if (!$db->query("UPDATE `".$table."` SET enabled=0, takeover_state='disabled', config_json='".$jsonEsc."', updated='".$nowEsc."' WHERE id=".$id))
  {
    throw new RuntimeException('Connector-Status konnte nicht gespeichert werden: '.$db->error);
  }

  $remaining = glob($configDir.'/connection-*.conf') ?: array();
  if ($remaining)
  {
    passthru('systemctl start bratonien-nc-connector.timer', $startExit);
    if ($startExit !== 0) throw new RuntimeException('Connector-Timer konnte nicht wieder gestartet werden.');
    echo "Verbindung #".$id." deaktiviert. Andere Connector-Verbindungen bleiben aktiv.\n";
  }
  else
  {
    passthru('systemctl disable bratonien-nc-connector.timer', $disableExit);
    echo "Verbindung #".$id." deaktiviert. Es gibt keine weitere aktive Connector-Verbindung; der Timer bleibt gestoppt.\n";
  }
}
catch (Throwable $e)
{
  fwrite(STDERR, $e->getMessage()."\n");
  exit(1);
}
