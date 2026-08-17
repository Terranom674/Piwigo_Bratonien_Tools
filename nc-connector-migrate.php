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

$configPath = '/etc/piwigo-sync/piwigo.conf';
$storagePath = '/etc/piwigo-sync/storages.tsv';
$bundlePath = '/tmp/bratonien-tools-nc-import.json';

function readLegacyConfig($path)
{
  if (!is_readable($path))
  {
    throw new RuntimeException('Legacy-Konfiguration nicht lesbar: '.$path);
  }

  $config = array();
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line)
  {
    $line = trim($line);
    if ($line === '' || $line[0] === '#')
    {
      continue;
    }
    if (!preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $matches))
    {
      continue;
    }
    $value = trim($matches[2]);
    if (strlen($value) >= 2)
    {
      $first = $value[0];
      $last = $value[strlen($value)-1];
      if (($first === '"' && $last === '"') || ($first === "'" && $last === "'"))
      {
        $value = substr($value, 1, -1);
      }
    }
    $config[$matches[1]] = $value;
  }

  return $config;
}

function readStorages($path)
{
  $storages = array();
  if (!is_readable($path))
  {
    return $storages;
  }

  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line)
  {
    if ($line === '' || $line[0] === '#')
    {
      continue;
    }
    $parts = explode("\t", $line);
    if (count($parts) !== 3)
    {
      continue;
    }
    $storages[] = array(
      'storage_id' => $parts[0],
      'source_prefix' => $parts[1],
      'local_mount' => $parts[2],
    );
  }

  return $storages;
}

try
{
  $config = readLegacyConfig($configPath);
  $passwordPath = isset($config['NC_DB_PASSWORD_FILE']) ? $config['NC_DB_PASSWORD_FILE'] : '/etc/piwigo-sync/nextcloud-db-password';
  if (!is_readable($passwordPath))
  {
    throw new RuntimeException('Nextcloud-Datenbankpasswort nicht lesbar: '.$passwordPath);
  }

  $password = trim((string)file_get_contents($passwordPath));
  if ($password === '')
  {
    throw new RuntimeException('Nextcloud-Datenbankpasswort ist leer.');
  }

  $bundle = array(
    'format' => 1,
    'created_at' => gmdate('c'),
    'config' => $config,
    'db_password' => $password,
    'storages' => readStorages($storagePath),
  );

  $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!is_string($json))
  {
    throw new RuntimeException('Migrationspaket konnte nicht erzeugt werden.');
  }

  if (file_put_contents($bundlePath, $json."\n", LOCK_EX) === false)
  {
    throw new RuntimeException('Migrationspaket konnte nicht geschrieben werden: '.$bundlePath);
  }

  @chmod($bundlePath, 0600);
  if (!@chown($bundlePath, 'www-data'))
  {
    @unlink($bundlePath);
    throw new RuntimeException('Migrationspaket konnte nicht sicher an www-data uebergeben werden.');
  }
  @chgrp($bundlePath, 'www-data');

  echo "Migrationspaket wurde bereitgestellt.\n";
  echo "Jetzt in Piwigo -> Bratonien Tools -> NC Connector wechseln und 'Bestehende Verbindung importieren' ausfuehren.\n";
  echo "Der laufende piwigo-sync wurde nicht veraendert oder gestoppt.\n";
}
catch (Throwable $e)
{
  fwrite(STDERR, 'Fehler: '.$e->getMessage()."\n");
  exit(1);
}
