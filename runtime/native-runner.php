#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

$options = getopt('', array('connection-id::'));
$connectionId = isset($options['connection-id']) ? (int)$options['connection-id'] : 0;

$pluginRoot = dirname(__DIR__);
$piwigoRoot = dirname($pluginRoot, 2);
$base = rtrim($piwigoRoot, '/').'/_data/bratonien-tools';
$schedulerDir = $base.'/nc-connector-scheduler';
$stateFile = $schedulerDir.'/state.json';
$runtimeDir = $base.'/nc-connector-runtime';
$stateRoot = $base.'/nc-connector-state';

function native_scheduler_state($path)
{
  if (!is_readable($path)) return array();
  $decoded = json_decode((string)@file_get_contents($path), true);
  return is_array($decoded) ? $decoded : array();
}

function native_scheduler_write($path, array $state)
{
  $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if (!is_string($json)) return false;
  $tmp = $path.'.tmp';
  if (@file_put_contents($tmp, $json."\n", LOCK_EX) === false) return false;
  @chmod($tmp, 0640);
  return @rename($tmp, $path);
}

foreach (array($schedulerDir, $runtimeDir, $stateRoot) as $dir)
{
  if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir))
  {
    fwrite(STDERR, "Runtime-Verzeichnis konnte nicht angelegt werden: {$dir}\n");
    exit(1);
  }
}

$state = native_scheduler_state($stateFile);
$state['enabled'] = true;
$state['mode'] = 'piwigo-native';
$state['state'] = 'running';
$state['message'] = $connectionId > 0 ? 'NC-Abgleich für Verbindung #'.$connectionId.' läuft.' : 'NC-Abgleich läuft.';
$state['started_at'] = time();
$state['timestamp'] = time();
$state['connection_id'] = $connectionId;
native_scheduler_write($stateFile, $state);

$env = $_ENV;
$env['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
$env['BRATONIEN_NC_NATIVE'] = '1';
$env['BRATONIEN_NC_PIWIGO_ROOT'] = $piwigoRoot;
$env['BRATONIEN_NC_CONFIG_DIR'] = $runtimeDir;
$env['BRATONIEN_NC_STATE_ROOT'] = $stateRoot;
$env['BRATONIEN_NC_CONNECTION_ID'] = (string)$connectionId;
$env['LC_ALL'] = 'C';
$env['LANG'] = 'C';

$bash = is_executable('/usr/bin/bash') ? '/usr/bin/bash' : '/bin/bash';
$command = array($bash, $pluginRoot.'/runtime/run-all.sh');
$spec = array(
  0=>array('file','/dev/null','r'),
  1=>array('pipe','w'),
  2=>array('pipe','w'),
);
$process = @proc_open($command, $spec, $pipes, null, $env);
$stdout = '';
$stderr = '';
$exit = 1;
if (is_resource($process))
{
  $stdout = (string)stream_get_contents($pipes[1]);
  $stderr = (string)stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit = proc_close($process);
}
else
{
  $stderr = 'run-all.sh konnte nicht gestartet werden.';
}

$state = native_scheduler_state($stateFile);
$state['enabled'] = true;
$state['mode'] = 'piwigo-native';
$state['state'] = $exit === 0 ? 'success' : 'error';
$state['message'] = $exit === 0
  ? ($connectionId > 0 ? 'NC-Abgleich für Verbindung #'.$connectionId.' erfolgreich abgeschlossen.' : 'NC-Abgleich erfolgreich abgeschlossen.')
  : ($connectionId > 0 ? 'NC-Abgleich für Verbindung #'.$connectionId.' fehlgeschlagen.' : 'NC-Abgleich fehlgeschlagen.');
$state['timestamp'] = time();
$state['finished_at'] = time();
$state['exit_code'] = $exit;
$state['connection_id'] = $connectionId;
$state['stdout'] = trim($stdout);
$state['stderr'] = trim($stderr);
if (empty($state['next_due']) || (int)$state['next_due'] < time())
{
  $state['next_due'] = time() + 60;
}
native_scheduler_write($stateFile, $state);
exit($exit === 0 ? 0 : 1);
