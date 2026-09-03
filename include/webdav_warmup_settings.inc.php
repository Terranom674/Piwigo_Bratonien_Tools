<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_webdav_warmup_settings_file()
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.settings.json';
}

function bratonien_tools_get_webdav_warmup_settings()
{
  $settings = array(
    'enabled'=>false,
    'batch_size'=>10,
    'periodic_hours'=>12,
  );

  $file = bratonien_tools_webdav_warmup_settings_file();
  if (is_file($file) && is_readable($file))
  {
    $raw = @file_get_contents($file);
    $saved = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($saved))
    {
      if (array_key_exists('enabled', $saved)) $settings['enabled'] = (bool)$saved['enabled'];
      if (isset($saved['batch_size'])) $settings['batch_size'] = max(1, min(50, (int)$saved['batch_size']));
      if (isset($saved['periodic_hours'])) $settings['periodic_hours'] = max(1, min(168, (int)$saved['periodic_hours']));
    }
  }
  return $settings;
}

function bratonien_tools_save_webdav_warmup_settings()
{
  $current = bratonien_tools_get_webdav_warmup_settings();
  $enabled = !empty($_POST['webdav_warmup_enabled']);
  $batch_size = isset($_POST['webdav_warmup_batch_size']) ? (int)$_POST['webdav_warmup_batch_size'] : (int)$current['batch_size'];
  $periodic_hours = isset($_POST['webdav_warmup_periodic_hours']) ? (int)$_POST['webdav_warmup_periodic_hours'] : (int)$current['periodic_hours'];
  $batch_size = max(1, min(50, $batch_size));
  $periodic_hours = max(1, min(168, $periodic_hours));

  $payload = array(
    'enabled'=>$enabled,
    'batch_size'=>$batch_size,
    'periodic_hours'=>$periodic_hours,
  );
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('Warmup-Einstellungen konnten nicht serialisiert werden.');

  $file = bratonien_tools_webdav_warmup_settings_file();
  $directory = dirname($file);
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory))
  {
    throw new RuntimeException('Warmup-Einstellungsverzeichnis konnte nicht angelegt werden.');
  }
  $tmp = $file.'.tmp-'.bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, $json."\n", LOCK_EX) === false)
  {
    throw new RuntimeException('Warmup-Einstellungen konnten nicht gespeichert werden.');
  }
  @chmod($tmp, 0664);
  if (!@rename($tmp, $file))
  {
    @unlink($tmp);
    throw new RuntimeException('Warmup-Einstellungen konnten nicht atomar gespeichert werden.');
  }

  return array('message'=>sprintf(
    'WebDAV-Worker gespeichert: %s, %d Bilder pro Batch, Shadow-Tree-Abgleich alle %d Stunden. Der Worker-Index wird bei Bedarf automatisch aus dem Shadow-Tree aufgebaut.',
    $enabled ? 'automatisch aktiv' : 'Automatik deaktiviert',
    $batch_size,
    $periodic_hours
  ));
}

function bratonien_tools_webdav_warmup_php_cli()
{
  if (function_exists('bratonien_tools_find_php_cli'))
  {
    $php = bratonien_tools_find_php_cli();
    if ($php) return $php;
  }
  foreach (array('/usr/bin/php', '/usr/bin/php8.4', '/usr/bin/php8.3', '/usr/local/bin/php') as $candidate)
  {
    if (is_file($candidate) && is_executable($candidate)) return $candidate;
  }
  return null;
}

function bratonien_tools_start_webdav_warmup_mode($mode, $message)
{
  if (!function_exists('exec')) throw new RuntimeException('PHP exec() ist deaktiviert; Worker kann nicht gestartet werden.');
  $php = bratonien_tools_webdav_warmup_php_cli();
  if (!$php) throw new RuntimeException('PHP-CLI wurde für den WebDAV-Worker nicht gefunden.');
  $dispatcher = realpath(BRATONIEN_TOOLS_PATH.'runtime/webdav-warmup-dispatch.php');
  if (!$dispatcher || !is_file($dispatcher)) throw new RuntimeException('WebDAV-Worker-Dispatcher wurde nicht gefunden.');

  if (!in_array($mode, array('manual','rebuild'), true)) throw new RuntimeException('Ungültiger manueller Worker-Modus.');
  $log = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup-dispatch.log';
  $command = 'nohup '.escapeshellarg($php).' '.escapeshellarg($dispatcher).' --mode='.escapeshellarg($mode).' >> '.escapeshellarg($log).' 2>&1 < /dev/null & echo $!';
  $output = array();
  $exit = 1;
  @exec($command, $output, $exit);
  $pid = isset($output[0]) ? (int)$output[0] : 0;
  if ($exit !== 0 || $pid <= 0) throw new RuntimeException('WebDAV-Worker konnte nicht gestartet werden.');

  return array('message'=>$message, 'pid'=>$pid);
}

function bratonien_tools_start_webdav_warmup_manual()
{
  return bratonien_tools_start_webdav_warmup_mode(
    'manual',
    'WebDAV-Worker: Shadow-Tree wird mit dem eigenen Index auf neue oder geänderte Quellen abgeglichen.'
  );
}

function bratonien_tools_start_webdav_cache_rebuild()
{
  return bratonien_tools_start_webdav_warmup_mode(
    'rebuild',
    'WebDAV-Bildcache: der vollständige aktuelle Shadow-Bestand wird erneut an Piwigo übergeben.'
  );
}

function bratonien_tools_has_local_cache_sources()
{
  if (!function_exists('bratonien_tools_webdav_materialize_source_info')) return true;

  $result = pwg_query('SELECT id FROM '.IMAGES_TABLE.' ORDER BY id');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $image_id = (int)($row['id'] ?? 0);
    if ($image_id < 1) continue;
    if (!bratonien_tools_webdav_materialize_source_info($image_id)) return true;
  }
  return false;
}

function bratonien_tools_start_combined_image_cache_build()
{
  $main = array('started'=>false, 'message'=>'');

  if (bratonien_tools_has_local_cache_sources())
  {
    $main = bratonien_tools_start_main_cache_build();
  }
  else
  {
    bratonien_tools_write_main_cache_status(array(
      'state'=>'complete',
      'message'=>'Lokaler Piwigo-Teil nicht erforderlich. Der WebDAV-Worker arbeitet ausschließlich mit Shadow-Tree und eigenem Index.',
      'total'=>0,
      'completed'=>0,
      'generated'=>0,
      'cached'=>0,
      'skipped'=>0,
      'errors'=>0,
      'current'=>'',
      'worker_count'=>0,
    ));
    $main['message'] = 'Lokaler Piwigo-Teil nicht gestartet, weil keine lokalen Bildquellen vorhanden sind.';
  }

  $webdav = bratonien_tools_start_webdav_cache_rebuild();
  $parts = array();
  if (!empty($main['message'])) $parts[] = $main['message'];
  if (!empty($webdav['message'])) $parts[] = $webdav['message'];

  return array(
    'started'=>!empty($main['started']) || !empty($webdav['pid']),
    'message'=>implode(' ', $parts),
  );
}

function bratonien_tools_run_webdav_warmup_audit()
{
  if (!function_exists('exec')) throw new RuntimeException('PHP exec() ist deaktiviert; Pfadaudit kann nicht gestartet werden.');
  $php = bratonien_tools_webdav_warmup_php_cli();
  if (!$php) throw new RuntimeException('PHP-CLI wurde für den Pfadaudit nicht gefunden.');
  $audit = realpath(BRATONIEN_TOOLS_PATH.'runtime/lib/webdav-warmup-audit.php');
  if (!$audit || !is_file($audit)) throw new RuntimeException('WebDAV-Pfadaudit wurde nicht gefunden.');

  $output = array();
  $exit = 1;
  @exec(escapeshellarg($php).' '.escapeshellarg($audit).' 2>&1', $output, $exit);
  $text = trim(implode(' | ', array_slice($output, -8)));
  if ($exit !== 0) throw new RuntimeException('WebDAV-Pfadaudit meldet einen unsicheren Zustand'.($text !== '' ? ': '.$text : '.'));
  return array('message'=>'WebDAV-Pfadaudit erfolgreich'.($text !== '' ? ': '.$text : '.'));
}

function bratonien_tools_get_webdav_warmup_status()
{
  $status = array(
    'state'=>'idle',
    'message'=>'Noch kein Shadow-Tree-/Worker-Lauf protokolliert.',
    'updated_at'=>0,
  );
  $files = glob(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.status-*.json');
  if (!$files) return $status;

  $latest = null;
  foreach ($files as $file)
  {
    if (!is_readable($file)) continue;
    $raw = @file_get_contents($file);
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data)) continue;
    if ($latest === null || (int)($data['updated_at'] ?? 0) > (int)($latest['updated_at'] ?? 0)) $latest = $data;
  }
  return is_array($latest) ? array_merge($status, $latest) : $status;
}
