<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_presentation_refresh_queue_dir()
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-presentation-refresh';
}

function bratonien_tools_presentation_refresh_normalize_categories(array $category_ids)
{
  $result = array();
  foreach ($category_ids as $category_id)
  {
    $category_id = (int)$category_id;
    if ($category_id > 0) $result[$category_id] = $category_id;
  }
  ksort($result, SORT_NUMERIC);
  return array_values($result);
}

function bratonien_tools_presentation_refresh_start()
{
  if (!function_exists('exec'))
  {
    error_log('Bratonien presentation refresh: PHP exec() ist deaktiviert; Auftrag bleibt in der Queue.');
    return false;
  }

  $php = function_exists('bratonien_tools_webdav_warmup_php_cli')
    ? bratonien_tools_webdav_warmup_php_cli()
    : null;
  if (!$php)
  {
    foreach (array('/usr/bin/php', '/usr/bin/php8.4', '/usr/bin/php8.3', '/usr/local/bin/php') as $candidate)
    {
      if (is_file($candidate) && is_executable($candidate))
      {
        $php = $candidate;
        break;
      }
    }
  }
  if (!$php)
  {
    error_log('Bratonien presentation refresh: PHP-CLI wurde nicht gefunden; Auftrag bleibt in der Queue.');
    return false;
  }

  $worker = realpath(BRATONIEN_TOOLS_PATH.'runtime/piwigo-presentation-refresh.php');
  if (!$worker || !is_file($worker))
  {
    error_log('Bratonien presentation refresh: Worker fehlt; Auftrag bleibt in der Queue.');
    return false;
  }

  $log = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-presentation-refresh.log';
  $command = 'nohup '.escapeshellarg($php).' '.escapeshellarg($worker)
    .' >> '.escapeshellarg($log).' 2>&1 < /dev/null & echo $!';
  $output = array();
  $exit = 1;
  @exec($command, $output, $exit);
  return $exit === 0 && !empty($output[0]) && (int)$output[0] > 0;
}

function bratonien_tools_presentation_refresh_enqueue(array $category_ids, $reason='presentation-changed', $all=false)
{
  $category_ids = bratonien_tools_presentation_refresh_normalize_categories($category_ids);
  $all = (bool)$all;
  if (!$all && !$category_ids) return array('queued'=>false, 'started'=>false);

  $dir = bratonien_tools_presentation_refresh_queue_dir();
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
  {
    throw new RuntimeException('Queue für die Vorschau-Aktualisierung konnte nicht angelegt werden.');
  }

  $payload = array(
    'all'=>$all,
    'categories'=>$category_ids,
    'reason'=>(string)$reason,
    'requested_at'=>time(),
  );
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('Vorschau-Auftrag konnte nicht serialisiert werden.');

  $name = sprintf('%010d-%s.json', time(), bin2hex(random_bytes(6)));
  $target = $dir.'/'.$name;
  $tmp = $target.'.tmp';
  if (@file_put_contents($tmp, $json."\n", LOCK_EX) === false)
  {
    throw new RuntimeException('Vorschau-Auftrag konnte nicht geschrieben werden.');
  }
  @chmod($tmp, 0664);
  if (!@rename($tmp, $target))
  {
    @unlink($tmp);
    throw new RuntimeException('Vorschau-Auftrag konnte nicht atomar bereitgestellt werden.');
  }

  return array('queued'=>true, 'started'=>bratonien_tools_presentation_refresh_start());
}

function bratonien_tools_presentation_refresh_enqueue_all($reason='presentation-changed')
{
  return bratonien_tools_presentation_refresh_enqueue(array(), $reason, true);
}

function bratonien_tools_presentation_refresh_category_snapshot()
{
  $snapshot = array();
  $result = pwg_query('SELECT id, status, visible FROM '.CATEGORIES_TABLE.' ORDER BY id');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $snapshot[(int)$row['id']] = array(
      'status'=>(string)$row['status'],
      'visible'=>(string)$row['visible'],
    );
  }
  return $snapshot;
}

function bratonien_tools_presentation_refresh_watch_admin_categories()
{
  if (!defined('IN_ADMIN') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

  // Connector-Sync hat einen eigenen, priorisierten Bildcache-Pfad. Neue
  // Kategorien werden dort bereits korrekt an den Worker übergeben.
  if ((string)($_GET['page'] ?? '') === 'site_update' && (string)($_POST['bratonien_connector'] ?? '') === '1') return;

  $before = bratonien_tools_presentation_refresh_category_snapshot();
  register_shutdown_function(function () use ($before) {
    try
    {
      $after = bratonien_tools_presentation_refresh_category_snapshot();
      $changed = array();
      foreach ($after as $category_id=>$state)
      {
        if (!isset($before[$category_id])) continue;
        if (
          (string)$before[$category_id]['status'] !== (string)$state['status']
          || (string)$before[$category_id]['visible'] !== (string)$state['visible']
        )
        {
          $changed[] = (int)$category_id;
        }
      }

      if ($changed)
      {
        bratonien_tools_presentation_refresh_enqueue($changed, 'album-status-or-visibility-changed');
      }
    }
    catch (Throwable $e)
    {
      error_log('Bratonien presentation refresh watcher: '.$e->getMessage());
    }
  });
}
