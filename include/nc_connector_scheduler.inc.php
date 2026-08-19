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

function bratonien_tools_nc_scheduler_spawn($force = false)
{
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
  $state['message'] = 'NC-Abgleich wurde angefordert.';
  $state['queued_at'] = $now;
  $state['timestamp'] = $now;
  $state['next_due'] = $now + bratonien_tools_nc_scheduler_interval();
  bratonien_tools_nc_scheduler_write_state($state);

  $runner = BRATONIEN_TOOLS_PATH.'runtime/native-runner.php';
  $command = escapeshellarg($php).' '.escapeshellarg($runner).' >> '.escapeshellarg($paths['log']).' 2>&1 &';
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
    throw new RuntimeException('Der native NC-Abgleich konnte nicht gestartet werden.');
  }

  return array('started'=>true, 'message'=>'NC-Abgleich wurde angefordert.');
}

function bratonien_tools_nc_scheduler_tick()
{
  $state = bratonien_tools_nc_scheduler_read_state();
  if (isset($state['enabled']) && !$state['enabled']) return;
  $next_due = (int)($state['next_due'] ?? 0);
  if ($next_due > time()) return;

  try
  {
    bratonien_tools_nc_scheduler_spawn(false);
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
