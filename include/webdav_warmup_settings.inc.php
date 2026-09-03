<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(BRATONIEN_TOOLS_PATH.'include/webdav_source_index.inc.php');

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

function bratonien_tools_webdav_warmup_connections()
{
  $result = array();
  if (!function_exists('bratonien_tools_nc_connector_connections') || !function_exists('bratonien_tools_nc_connector_is_webdav')) return $result;

  foreach (bratonien_tools_nc_connector_connections() as $connection)
  {
    if (empty($connection['enabled']) || !bratonien_tools_nc_connector_is_webdav($connection)) continue;
    $connection_id = (int)($connection['id'] ?? 0);
    if ($connection_id < 1) continue;
    $config = isset($connection['config']) && is_array($connection['config']) ? $connection['config'] : array();
    $state_dir = rtrim((string)($config['state_dir'] ?? ''), '/');
    if ($state_dir === '') continue;
    $result[$connection_id] = array('id'=>$connection_id, 'state_dir'=>$state_dir, 'connection'=>$connection);
  }
  return $result;
}

function bratonien_tools_webdav_warmup_status_file_for_connection($connection_id)
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.status-'.(int)$connection_id.'.json';
}

function bratonien_tools_webdav_warmup_cancel_file_for_connection($connection_id)
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.cancel-'.(int)$connection_id;
}

function bratonien_tools_webdav_warmup_write_status($connection_id, $state, $message, array $extra=array())
{
  $file = bratonien_tools_webdav_warmup_status_file_for_connection($connection_id);
  $payload = array_merge(array(
    'state'=>(string)$state,
    'message'=>(string)$message,
    'connection_id'=>(int)$connection_id,
    'updated_at'=>time(),
  ), $extra);
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('WebDAV-Worker-Status konnte nicht serialisiert werden.');
  $directory = dirname($file);
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('WebDAV-Worker-Statusverzeichnis konnte nicht angelegt werden.');
  $tmp = $file.'.tmp-'.bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, $json."\n", LOCK_EX) === false) throw new RuntimeException('WebDAV-Worker-Status konnte nicht geschrieben werden.');
  @chmod($tmp, 0664);
  if (!@rename($tmp, $file))
  {
    @unlink($tmp);
    throw new RuntimeException('WebDAV-Worker-Status konnte nicht atomar gespeichert werden.');
  }
}

function bratonien_tools_invalidate_webdav_cache_completion($reason='Piwigo-Bildcache wurde geleert.')
{
  $invalidated = 0;
  foreach (bratonien_tools_webdav_warmup_connections() as $connection_id=>$runtime)
  {
    $index = bratonien_tools_webdav_source_index_load($runtime['state_dir'], $connection_id);
    if (is_array($index))
    {
      foreach ($index['sources'] as &$entry)
      {
        if (!is_array($entry)) continue;
        unset($entry['stage1_signature'], $entry['stage2_signature']);
      }
      unset($entry);
      bratonien_tools_webdav_source_index_save($runtime['state_dir'], $connection_id, $index);
      $invalidated++;
    }

    bratonien_tools_webdav_warmup_write_status(
      $connection_id,
      'idle',
      $reason.' Der Quellenindex bleibt erhalten; seine Cache-Fertigmarkierungen wurden verworfen.',
      array('cache_invalidated'=>true)
    );
  }
  return $invalidated;
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

  $connections = bratonien_tools_webdav_warmup_connections();
  if (!$connections) throw new RuntimeException('Keine aktive WebDAV-Verbindung mit vollständiger Runtime gefunden.');

  $run_id = date('YmdHis').'-'.bin2hex(random_bytes(4));
  foreach ($connections as $connection_id=>$runtime)
  {
    // Ein altes Abbruchsignal darf niemals den nächsten bewusst gestarteten
    // Lauf stoppen. Es wird unmittelbar vor dem neuen Start entfernt.
    @unlink(bratonien_tools_webdav_warmup_cancel_file_for_connection($connection_id));

    bratonien_tools_webdav_warmup_write_status(
      $connection_id,
      'queued',
      $mode === 'rebuild'
        ? 'Cache-Rebuild wurde angefordert. Der Worker wartet auf den Start und gleicht danach Shadow-Tree und eigenen Index ab.'
        : 'Cache-Abgleich wurde angefordert. Bereits erfolgreich abgeschlossene Stufen bleiben erhalten; geladen werden nur Quellen mit fehlender Stufe 1 oder Stufe 2.',
      array('mode'=>$mode, 'run_id'=>$run_id)
    );
  }

  $log = PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup-dispatch.log';
  $command = 'nohup '.escapeshellarg($php).' '.escapeshellarg($dispatcher).' --mode='.escapeshellarg($mode).' >> '.escapeshellarg($log).' 2>&1 < /dev/null & echo $!';
  $output = array();
  $exit = 1;
  @exec($command, $output, $exit);
  $pid = isset($output[0]) ? (int)$output[0] : 0;
  if ($exit !== 0 || $pid <= 0)
  {
    foreach ($connections as $connection_id=>$runtime)
    {
      bratonien_tools_webdav_warmup_write_status($connection_id, 'error', 'WebDAV-Worker konnte nicht gestartet werden.', array('mode'=>$mode, 'run_id'=>$run_id));
    }
    throw new RuntimeException('WebDAV-Worker konnte nicht gestartet werden.');
  }

  return array('message'=>$message, 'pid'=>$pid, 'run_id'=>$run_id);
}

function bratonien_tools_start_webdav_warmup_manual()
{
  return bratonien_tools_start_webdav_warmup_mode(
    'manual',
    'WebDAV-Worker: Shadow-Tree und Worker-Index werden abgeglichen; vollständig erledigte Quellen werden nicht aus Nextcloud geladen.'
  );
}

function bratonien_tools_start_webdav_cache_rebuild()
{
  return bratonien_tools_start_webdav_warmup_mode(
    'rebuild',
    'WebDAV-Bildcache: der vollständige aktuelle Shadow-Bestand wird erneut an Piwigo übergeben.'
  );
}

function bratonien_tools_request_webdav_cache_cancel()
{
  $requested = 0;
  foreach (bratonien_tools_webdav_warmup_connections() as $connection_id=>$runtime)
  {
    $status_file = bratonien_tools_webdav_warmup_status_file_for_connection($connection_id);
    $status = null;
    if (is_file($status_file) && is_readable($status_file))
    {
      $raw = @file_get_contents($status_file);
      $status = $raw !== false ? json_decode($raw, true) : null;
    }
    if (!is_array($status)) $status = array();
    $state = (string)($status['state'] ?? 'idle');
    if (!in_array($state, array('queued','scan','running','cancelling'), true)) continue;

    $cancel = bratonien_tools_webdav_warmup_cancel_file_for_connection($connection_id);
    if (@file_put_contents($cancel, (string)time()."\n", LOCK_EX) === false)
    {
      throw new RuntimeException('WebDAV-Abbruchsignal für Verbindung #'.(int)$connection_id.' konnte nicht geschrieben werden.');
    }
    @chmod($cancel, 0664);

    $extra = $status;
    unset($extra['state'], $extra['message'], $extra['connection_id'], $extra['updated_at']);
    $extra['cancel_requested'] = true;
    bratonien_tools_webdav_warmup_write_status(
      $connection_id,
      'cancelling',
      'Abbruch angefordert. Der aktuell laufende Batch wird noch vollständig beendet; danach werden keine weiteren Nextcloud-Originale geladen.',
      $extra
    );
    $requested++;
  }
  return $requested;
}

function bratonien_tools_cancel_combined_image_cache_build()
{
  $webdav = bratonien_tools_request_webdav_cache_cancel();
  $local = false;
  if (function_exists('bratonien_tools_main_cache_process_active') && function_exists('bratonien_tools_main_cache_is_running'))
  {
    $local = bratonien_tools_main_cache_process_active() || bratonien_tools_main_cache_is_running();
  }
  if ($local && function_exists('bratonien_tools_request_main_cache_cancel'))
  {
    bratonien_tools_request_main_cache_cancel();
  }

  if ($webdav > 0 && $local)
  {
    return array('message'=>'Cache-Abbruch angefordert. Der aktuelle WebDAV-Batch wird noch beendet; lokale Cache-Worker erhalten ebenfalls sofort das Abbruchsignal.');
  }
  if ($webdav > 0)
  {
    return array('message'=>'WebDAV-Cache-Abbruch angefordert. Der aktuell laufende Batch wird noch vollständig beendet; danach ist Schluss.');
  }
  if ($local)
  {
    return array('message'=>'Lokaler Piwigo-Cache-Abbruch wurde angefordert.');
  }

  return array('message'=>'Es läuft aktuell kein Cache-Aufbau.');
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
  // Der normale "Piwigo-Bildcache aufbauen"-Lauf ist immer inkrementell.
  // Der Worker liest zuerst ausschließlich Shadow-Tree/Mapping und seinen
  // eigenen Index. Nur Quellen, denen laut Index Stufe 1 oder Stufe 2 fehlt,
  // dürfen anschließend aus Nextcloud geladen werden. Ein kompletter
  // Neuaufbau entsteht nur nach einer echten Cache-Invalidierung (z.B. Cache
  // leeren), denn genau dort werden die Stage-Signaturen bewusst entfernt.
  $webdav = bratonien_tools_start_webdav_warmup_manual();
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