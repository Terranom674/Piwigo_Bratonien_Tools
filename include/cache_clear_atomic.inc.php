<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_atomic_cache_remove_tree($root, array &$failed)
{
  if (!file_exists($root) && !is_link($root)) return;

  // Niemals einem Symlink folgen. Auch ein Link auf ein Verzeichnis ist nur
  // ein einzelner Eintrag des ausgelagerten Cachebaums und wird per unlink()
  // entfernt. So kann das Cleanup kein Ziel ausserhalb des Cachebaums beruehren.
  if (is_link($root))
  {
    if (!@unlink($root)) $failed[] = $root;
    return;
  }
  if (is_file($root))
  {
    if (!@unlink($root)) $failed[] = $root;
    return;
  }
  if (!is_dir($root))
  {
    $failed[] = $root;
    return;
  }

  $entries = @scandir($root);
  if ($entries === false)
  {
    $failed[] = $root;
    return;
  }

  foreach ($entries as $entry)
  {
    if ($entry === '.' || $entry === '..') continue;
    $path = $root.DIRECTORY_SEPARATOR.$entry;

    if (is_link($path) || is_file($path))
    {
      if (!@unlink($path)) $failed[] = $path;
      continue;
    }

    if (is_dir($path))
    {
      bratonien_tools_atomic_cache_remove_tree($path, $failed);
      continue;
    }

    // Sonderdateien werden wie einzelne Cacheeintraege behandelt. unlink()
    // ist hier sicherer als ein rekursiver Iterator, der Verweise interpretieren
    // koennte.
    if (!@unlink($path)) $failed[] = $path;
  }

  if (!@rmdir($root) && is_dir($root)) $failed[] = $root;
}

function bratonien_tools_atomic_cache_acquire_lock($path, $label, $timeout_seconds=10.0)
{
  $directory = dirname($path);
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory))
  {
    throw new RuntimeException($label.'-Lockverzeichnis konnte nicht angelegt werden.');
  }

  $handle = @fopen($path, 'c');
  if (!$handle)
  {
    throw new RuntimeException($label.'-Lock konnte nicht geöffnet werden: '.$path);
  }

  $deadline = microtime(true) + max(0.1, (float)$timeout_seconds);
  do
  {
    if (@flock($handle, LOCK_EX | LOCK_NB))
    {
      return $handle;
    }
    usleep(100000);
  }
  while (microtime(true) < $deadline);

  fclose($handle);
  throw new RuntimeException($label.' ist noch aktiv. Der Cache wurde nicht angefasst; bitte den laufenden Vorgang kurz abschließen lassen und erneut leeren.');
}

function bratonien_tools_atomic_cache_release_locks(array &$locks)
{
  foreach (array_reverse($locks) as $handle)
  {
    if (!is_resource($handle)) continue;
    @flock($handle, LOCK_UN);
    fclose($handle);
  }
  $locks = array();
}

function bratonien_tools_atomic_cache_prepare_webdav_guards(array &$locks, array &$cancel_files)
{
  if (!function_exists('bratonien_tools_webdav_warmup_connections')) return 0;

  $connections = bratonien_tools_webdav_warmup_connections();
  if (!$connections) return 0;
  ksort($connections, SORT_NUMERIC);

  // Jeder aktive WebDAV-Worker bekommt zuerst sein reguläres Abbruchsignal.
  // Ein bereits gestarteter 10er-Batch darf sauber fertig werden; ein neuer
  // Batch darf danach nicht mehr beginnen.
  if (function_exists('bratonien_tools_request_webdav_cache_cancel'))
  {
    bratonien_tools_request_webdav_cache_cancel();
  }

  // Das Signal wird für alle Verbindungen gesetzt, auch wenn deren Statusdatei
  // veraltet sein sollte. Sobald wir anschließend den Prozess-Lock besitzen,
  // kann garantiert kein WebDAV-Worker während des Cache-Leerens schreiben.
  foreach ($connections as $connection_id=>$runtime)
  {
    $cancel = function_exists('bratonien_tools_webdav_warmup_cancel_file_for_connection')
      ? bratonien_tools_webdav_warmup_cancel_file_for_connection($connection_id)
      : PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.cancel-'.(int)$connection_id;

    if (@file_put_contents($cancel, (string)time()."\n", LOCK_EX) === false)
    {
      throw new RuntimeException('WebDAV-Abbruchsignal für Verbindung #'.(int)$connection_id.' konnte nicht geschrieben werden.');
    }
    @chmod($cancel, 0664);
    $cancel_files[] = $cancel;
  }

  foreach ($connections as $connection_id=>$runtime)
  {
    $state_dir = rtrim((string)($runtime['state_dir'] ?? ''), '/');
    if ($state_dir === '')
    {
      throw new RuntimeException('WebDAV-State-Verzeichnis für Verbindung #'.(int)$connection_id.' fehlt.');
    }
    $locks[] = bratonien_tools_atomic_cache_acquire_lock(
      $state_dir.'/webdav-cache-warmup.lock',
      'WebDAV-Cache-Worker #'.(int)$connection_id,
      10.0
    );
  }

  return count($connections);
}

function bratonien_tools_atomic_cache_prepare_presentation_guard(array &$locks)
{
  if (!function_exists('bratonien_tools_presentation_refresh_queue_dir')) return false;

  $queue_dir = bratonien_tools_presentation_refresh_queue_dir();
  if (!is_dir($queue_dir)) return false;

  // Der Presentation-Worker erzeugt Bratonien-Wasserzeichen-Vorschauen im
  // selben Derivatbaum. Deshalb muss auch er während des atomaren Leerens
  // vollständig draußen bleiben.
  $locks[] = bratonien_tools_atomic_cache_acquire_lock(
    rtrim($queue_dir, '/').'/.worker.lock',
    'Vorschau-Aktualisierung',
    10.0
  );
  return true;
}

function bratonien_tools_clear_image_cache_atomic()
{
  global $conf;

  if (!defined('PWG_DERIVATIVE_DIR'))
  {
    throw new RuntimeException('PWG_DERIVATIVE_DIR ist nicht definiert.');
  }

  $held_locks = array();
  $webdav_cancel_files = array();
  $webdav_guards_ready = false;

  try
  {
    // 1. Lokalen Cache-Builder sauber anhalten und seinen Prozess-Lock selbst
    // halten. Damit kann zwischen Prüfung und atomarem Rename kein neuer
    // lokaler Builder starten.
    if (bratonien_tools_main_cache_process_active() || bratonien_tools_main_cache_is_running())
    {
      bratonien_tools_request_main_cache_cancel();
      if (!bratonien_tools_wait_main_cache_stopped(10.0))
      {
        throw new RuntimeException('Der laufende Cache-Aufbau konnte noch nicht beendet werden. Bitte den Abbruch kurz abschließen lassen und erneut leeren.');
      }
    }
    @unlink(bratonien_tools_main_cache_cancel_file());
    $held_locks[] = bratonien_tools_atomic_cache_acquire_lock(
      bratonien_tools_main_cache_lock_file(),
      'Lokaler Piwigo-Cache-Worker',
      10.0
    );

    // 2. WebDAV-Warmup regulär abbrechen und danach alle Verbindungs-Locks
    // exklusiv halten. Der laufende Batch wird nicht hart beendet.
    bratonien_tools_atomic_cache_prepare_webdav_guards($held_locks, $webdav_cancel_files);
    $webdav_guards_ready = true;

    // 3. Seit der Presentation-Refresh-Einführung kann auch dieser Worker
    // Derivate erzeugen. Ohne seinen Lock konnte ein gerade geleerter Cache
    // sofort wieder gefüllt werden und wie ein fehlgeschlagenes Löschen wirken.
    bratonien_tools_atomic_cache_prepare_presentation_guard($held_locks);

    $piwigo_root = realpath(PHPWG_ROOT_PATH);
    if ($piwigo_root === false)
    {
      throw new RuntimeException('Piwigo-Root konnte für die Cache-Sicherheitsprüfung nicht aufgelöst werden.');
    }

    $cache_root = rtrim(PHPWG_ROOT_PATH, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.trim(PWG_DERIVATIVE_DIR, '/\\');
    $real_cache_root = realpath($cache_root);
    if ($real_cache_root === false || !is_dir($real_cache_root))
    {
      throw new RuntimeException('Bildcache-Verzeichnis wurde nicht gefunden: '.$cache_root);
    }

    $root_prefix = rtrim($piwigo_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    if (strpos(rtrim($real_cache_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, $root_prefix) !== 0)
    {
      throw new RuntimeException('Bildcache liegt außerhalb der Piwigo-Installation. Abbruch.');
    }
    if (rtrim($real_cache_root, DIRECTORY_SEPARATOR) === rtrim($piwigo_root, DIRECTORY_SEPARATOR))
    {
      throw new RuntimeException('Bildcache-Pfad entspricht dem Piwigo-Root. Sicherheitsabbruch.');
    }

    $before = bratonien_tools_scan_image_cache($real_cache_root);
    $parent = dirname($real_cache_root);
    if (!is_dir($parent) || !is_writable($parent))
    {
      throw new RuntimeException('Übergeordnetes Bildcache-Verzeichnis ist nicht beschreibbar: '.$parent);
    }

    $detached = $parent.'/.'.basename($real_cache_root).'.bratonien-clear-'.date('YmdHis').'-'.bin2hex(random_bytes(4));
    if (file_exists($detached) || is_link($detached))
    {
      throw new RuntimeException('Temporäres Cache-Auslagerungsverzeichnis existiert bereits. Abbruch.');
    }

    if (!@rename($real_cache_root, $detached))
    {
      throw new RuntimeException('Bildcache konnte nicht atomar aus dem aktiven Pfad ausgelagert werden.');
    }

    $mode = isset($conf['chmod_value']) ? (int)$conf['chmod_value'] : 0755;
    $umask = umask(0);
    $created = @mkdir($real_cache_root, $mode, true);
    umask($umask);
    if (!$created && !is_dir($real_cache_root))
    {
      if (!file_exists($real_cache_root) && @rename($detached, $real_cache_root))
      {
        throw new RuntimeException('Neuer Bildcache konnte nicht angelegt werden; der bisherige Cache wurde vollständig wiederhergestellt.');
      }
      throw new RuntimeException('Neuer Bildcache konnte nicht angelegt und der bisherige Cache nicht automatisch wiederhergestellt werden. Manueller Eingriff erforderlich.');
    }

    @chmod($real_cache_root, $mode);
    @file_put_contents($real_cache_root.'/index.htm', 'Not allowed!');

    // Der Quellenindex selbst bleibt bestehen, weil sich die Quellen durch ein
    // Cache-Leeren nicht ändern. Seine Fertigmarkierungen sind danach aber
    // zwingend ungültig. Der Worker darf sonst einen leeren Piwigo-Cache als
    // bereits verarbeitet ansehen, ohne den Cache selbst anzuschauen.
    $webdav_invalidated = 0;
    if (function_exists('bratonien_tools_invalidate_webdav_cache_completion'))
    {
      $webdav_invalidated = bratonien_tools_invalidate_webdav_cache_completion('Piwigo-Bildcache wurde atomar geleert.');
    }

    $failed = array();
    bratonien_tools_atomic_cache_remove_tree($detached, $failed);

    $active = bratonien_tools_scan_image_cache($real_cache_root);

    bratonien_tools_write_main_cache_status(array(
      'state'=>'idle',
      'message'=>'Bildcache wurde atomar geleert. Kein Cache-Worker schreibt während des Löschvorgangs in den Derivatbaum.',
    ));

    if ($failed)
    {
      throw new RuntimeException(sprintf(
        'Der aktive Bildcache wurde erfolgreich geleert und neu angelegt, aber %d Datei(en)/Verzeichnis(se) des ausgelagerten Altbestands konnten nicht entfernt werden. Erste problematische Stelle: %s',
        count($failed),
        $failed[0]
      ));
    }

    $message = sprintf(
      'Bildcache atomar geleert: %d alte Datei(en) (%s) entfernt, davon %d Custom-Derivate.',
      $before['files'],
      bratonien_tools_format_bytes($before['bytes']),
      $before['custom']
    );

    if ($webdav_invalidated > 0)
    {
      $message .= sprintf(' Die Cache-Fertigmarkierungen von %d WebDAV-Worker-Index(en) wurden verworfen; die Quellenindizes selbst bleiben erhalten.', $webdav_invalidated);
    }

    if ($active['files'] > 0)
    {
      $message .= sprintf(
        ' Unmittelbar nach dem atomaren Umschalten wurden bereits %d neue Derivatdatei(en) durch normale Piwigo-Anfragen erzeugt; die eigenen Cache-Worker waren während des Löschens gesperrt und der alte Cachebaum wurde vollständig entfernt.',
        $active['files']
      );
    }
    else
    {
      $message .= ' Der neu aktive Cache ist zum Abschluss der Prüfung leer.';
    }

    return array('message'=>$message);
  }
  finally
  {
    // Nur wenn wirklich alle WebDAV-Prozess-Locks gesichert wurden, sind die
    // Cancel-Dateien nicht mehr nötig. Bei einem Timeout bleiben sie bestehen,
    // damit der noch laufende Worker nach seinem aktuellen Batch tatsächlich
    // stoppt und das fehlgeschlagene Cache-Leeren nicht durch einen Folgebatch
    // verschärft wird.
    if ($webdav_guards_ready)
    {
      foreach ($webdav_cancel_files as $cancel)
      {
        @unlink($cancel);
      }
    }
    bratonien_tools_atomic_cache_release_locks($held_locks);
  }
}
