<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_nc_scheduler_paths()
{
  $base = rtrim(PHPWG_ROOT_PATH, '/').'/_data/bratonien-tools';
  return array(
    'base'=>$base,
    'runtime'=>$base.'/nc-connector-runtime',
    'state_root'=>$base.'/nc-connector-state',
    'scheduler'=>$base.'/nc-connector-scheduler',
    'state'=>$base.'/nc-connector-scheduler/state.json',
    'trigger_lock'=>$base.'/nc-connector-scheduler/trigger.lock',
    'worker_lock'=>$base.'/nc-connector-scheduler/worker.lock',
    'log'=>$base.'/nc-connector-scheduler/last-worker.log',
    'status_root'=>$base.'/nc-connector-status',
  );
}

function bratonien_tools_nc_scheduler_interval()
{
  global $conf;
  $interval = isset($conf['bratonien_nc_scheduler_interval']) ? (int)$conf['bratonien_nc_scheduler_interval'] : 60;
  return max(60, $interval);
}

function bratonien_tools_nc_scheduler_ensure_dirs()
{
  foreach (bratonien_tools_nc_scheduler_paths() as $key=>$path)
  {
    if (in_array($key, array('state','trigger_lock','worker_lock','log'), true)) continue;
    if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path))
    {
      return false;
    }
  }
  return true;
}

function bratonien_tools_nc_scheduler_read_state()
{
  $paths = bratonien_tools_nc_scheduler_paths();
  if (!is_readable($paths['state'])) return array();
  $decoded = json_decode((string)@file_get_contents($paths['state']), true);
  return is_array($decoded) ? $decoded : array();
}

function bratonien_tools_nc_scheduler_write_state(array $state)
{
  $paths = bratonien_tools_nc_scheduler_paths();
  if (!bratonien_tools_nc_scheduler_ensure_dirs()) return false;
  $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if (!is_string($json)) return false;
  $tmp = $paths['state'].'.tmp';
  if (@file_put_contents($tmp, $json."\n", LOCK_EX) === false) return false;
  @chmod($tmp, 0640);
  return @rename($tmp, $paths['state']);
}

function bratonien_tools_nc_scheduler_write_connection_status($connection_id, $state, $message)
{
  $connection_id = (int)$connection_id;
  if ($connection_id < 1) return false;
  $paths = bratonien_tools_nc_scheduler_paths();
  if (!bratonien_tools_nc_scheduler_ensure_dirs()) return false;
  $target = $paths['status_root'].'/connection-'.$connection_id.'.json';
  $existing = array();
  if (is_readable($target))
  {
    $decoded = json_decode((string)@file_get_contents($target), true);
    if (is_array($decoded)) $existing = $decoded;
  }
  $existing['state'] = (string)$state;
  $existing['message'] = (string)$message;
  $existing['timestamp'] = time();
  $existing['connection_id'] = $connection_id;
  $json = json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if (!is_string($json)) return false;
  $tmp = $target.'.tmp';
  if (@file_put_contents($tmp, $json."\n", LOCK_EX) === false) return false;
  @chmod($tmp, 0644);
  return @rename($tmp, $target);
}

function bratonien_tools_nc_scheduler_install()
{
  if (!bratonien_tools_nc_scheduler_ensure_dirs()) return false;
  $state = bratonien_tools_nc_scheduler_read_state();
  if (empty($state['next_due']))
  {
    $state['next_due'] = time();
    $state['enabled'] = true;
    $state['mode'] = 'piwigo-native';
    bratonien_tools_nc_scheduler_write_state($state);
  }
  return true;
}

function bratonien_tools_nc_scheduler_php_binary()
{
  foreach (array('/usr/bin/php', '/usr/local/bin/php', PHP_BINARY) as $candidate)
  {
    if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) return $candidate;
  }
  return '';
}

function bratonien_tools_nc_scheduler_spawn($force = false, $connection_id = 0)
{
  $connection_id = (int)$connection_id;
  if ($force && $connection_id < 1)
  {
    throw new RuntimeException('Für den manuellen Abgleich fehlt die Verbindungs-ID.');
  }

  $paths = bratonien_tools_nc_scheduler_paths();
  if (!bratonien_tools_nc_scheduler_ensure_dirs())
  {
    throw new RuntimeException('Der native NC-Scheduler kann sein Laufzeitverzeichnis nicht anlegen.');
  }

  $lock = @fopen($paths['trigger_lock'], 'c+');
  if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB))
  {
    if (is_resource($lock)) fclose($lock);
    return array('started'=>false, 'message'=>'Ein NC-Abgleich wird bereits vorbereitet.');
  }

  $state = bratonien_tools_nc_scheduler_read_state();
  $now = time();
  $next_due = (int)($state['next_due'] ?? 0);
  if (!$force && $next_due > $now)
  {
    @flock($lock, LOCK_UN);
    fclose($lock);
    return array('started'=>false, 'message'=>'Der nächste NC-Abgleich ist noch nicht fällig.');
  }

  $php = bratonien_tools_nc_scheduler_php_binary();
  if ($php === '')
  {
    @flock($lock, LOCK_UN);
    fclose($lock);
    throw new RuntimeException('Kein ausführbares PHP-CLI für den nativen NC-Scheduler gefunden.');
  }

  $state['enabled'] = true;
  $state['mode'] = 'piwigo-native';
  $state['state'] = 'queued';
  $state['message'] = $connection_id > 0 ? 'NC-Abgleich für Verbindung #'.$connection_id.' wurde angefordert.' : 'NC-Abgleich wurde angefordert.';
  $state['queued_at'] = $now;
  $state['timestamp'] = $now;
  $state['connection_id'] = $connection_id;
  $state['next_due'] = $now + bratonien_tools_nc_scheduler_interval();
  bratonien_tools_nc_scheduler_write_state($state);
  if ($connection_id > 0)
  {
    bratonien_tools_nc_scheduler_write_connection_status($connection_id, 'queued', 'Abgleich wurde angefordert.');
  }

  $runner = BRATONIEN_TOOLS_PATH.'runtime/native-runner.php';
  $runner_args = $connection_id > 0 ? ' --connection-id='.escapeshellarg((string)$connection_id) : '';
  $command = escapeshellarg($php).' '.escapeshellarg($runner).$runner_args.' >> '.escapeshellarg($paths['log']).' 2>&1 &';
  $spec = array(
    0=>array('file','/dev/null','r'),
    1=>array('file','/dev/null','a'),
    2=>array('file','/dev/null','a'),
  );
  $process = function_exists('proc_open') ? @proc_open(array('/bin/sh','-c',$command), $spec, $pipes) : false;
  $exit = is_resource($process) ? proc_close($process) : 1;

  @flock($lock, LOCK_UN);
  fclose($lock);

  if ($exit !== 0)
  {
    $state = bratonien_tools_nc_scheduler_read_state();
    $state['state'] = 'error';
    $state['message'] = 'Der native NC-Abgleich konnte nicht gestartet werden.';
    $state['timestamp'] = time();
    bratonien_tools_nc_scheduler_write_state($state);
    if ($connection_id > 0)
    {
      bratonien_tools_nc_scheduler_write_connection_status($connection_id, 'error', 'Abgleich konnte nicht gestartet werden.');
    }
    throw new RuntimeException('Der native NC-Abgleich konnte nicht gestartet werden.');
  }

  return array('started'=>true, 'message'=>'Abgleich für Verbindung #'.$connection_id.' wurde angefordert.');
}

function bratonien_tools_nc_scheduler_tick()
{
  $state = bratonien_tools_nc_scheduler_read_state();
  if (isset($state['enabled']) && !$state['enabled']) return;
  $next_due = (int)($state['next_due'] ?? 0);
  if ($next_due > time()) return;

  try
  {
    bratonien_tools_nc_scheduler_spawn(false, 0);
  }
  catch (Throwable $e)
  {
    $state = bratonien_tools_nc_scheduler_read_state();
    $state['state'] = 'error';
    $state['message'] = $e->getMessage();
    $state['timestamp'] = time();
    $state['next_due'] = time() + bratonien_tools_nc_scheduler_interval();
    bratonien_tools_nc_scheduler_write_state($state);
  }
}
