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
  fwrite(STDERR, "Aufruf: php nc-connector-diagnose.php <connection-id>\n");
  exit(1);
}

$connectionId = (int)$argv[1];
$configPath = '/etc/bratonien-tools/nc-connector/connection-'.$connectionId.'.conf';
$runtime = '/opt/piwigo-sync/sync.sh';
$legacyTimer = 'piwigo-sync.timer';
$legacyService = 'piwigo-sync.service';

function runCommand(array $command, $allowFailure = false)
{
  $spec = array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
  );
  $process = proc_open($command, $spec, $pipes);
  if (!is_resource($process))
  {
    throw new RuntimeException('Prozess konnte nicht gestartet werden: '.implode(' ', $command));
  }
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit = proc_close($process);
  if ($exit !== 0 && !$allowFailure)
  {
    throw new RuntimeException('Befehl fehlgeschlagen: '.implode(' ', $command));
  }
  return array('exit'=>$exit, 'stdout'=>(string)$stdout, 'stderr'=>(string)$stderr);
}

$timerWasEnabled = false;

try
{
  if (!is_readable($configPath))
  {
    throw new RuntimeException('Connector-Konfiguration fehlt oder ist nicht lesbar: '.$configPath.'. Fuehre zuerst den Cutover-Versuch mindestens einmal aus, damit die Laufzeitkonfiguration erzeugt wird.');
  }
  if (!is_executable($runtime))
  {
    throw new RuntimeException('Sync-Runtime fehlt: '.$runtime);
  }

  $enabled = runCommand(array('systemctl', 'is-enabled', $legacyTimer), true);
  $timerWasEnabled = $enabled['exit'] === 0;

  echo "Stoppe Legacy-Timer fuer die Diagnose...\n";
  runCommand(array('systemctl', 'stop', $legacyTimer), true);

  $deadline = time() + 120;
  while (true)
  {
    $active = runCommand(array('systemctl', 'is-active', '--quiet', $legacyService), true);
    if ($active['exit'] !== 0)
    {
      break;
    }
    if (time() >= $deadline)
    {
      throw new RuntimeException('Ein laufender Legacy-Sync wurde nach 120 Sekunden nicht beendet.');
    }
    sleep(2);
  }

  echo "Fuehre Connector-Lauf im Diagnosemodus aus...\n\n";
  $result = runCommand(array('env', 'PIWIGO_CONFIG='.$configPath, 'bash', '-x', $runtime), true);

  echo "===== STDOUT =====\n";
  echo trim($result['stdout'])."\n";
  echo "===== STDERR / TRACE =====\n";
  echo trim($result['stderr'])."\n";
  echo "===== ENDE =====\n";
  echo 'Exit-Code: '.$result['exit']."\n";
}
catch (Throwable $e)
{
  fwrite(STDERR, 'Diagnose fehlgeschlagen: '.$e->getMessage()."\n");
}
finally
{
  if ($timerWasEnabled)
  {
    runCommand(array('systemctl', 'enable', '--now', $legacyTimer), true);
  }
  else
  {
    runCommand(array('systemctl', 'start', $legacyTimer), true);
  }
  echo "Legacy-Timer wurde nach der Diagnose wieder aktiviert/gestartet.\n";
}
