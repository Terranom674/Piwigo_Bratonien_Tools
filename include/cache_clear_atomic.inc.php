<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_atomic_cache_remove_tree($root, array &$failed)
{
  if (!file_exists($root) && !is_link($root)) return;

  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );

  foreach ($iterator as $item)
  {
    $path = $item->getPathname();
    if ($item->isLink() || $item->isFile())
    {
      if (!@unlink($path)) $failed[] = $path;
      continue;
    }

    if ($item->isDir() && !@rmdir($path))
    {
      $failed[] = $path;
    }
  }

  if (!@rmdir($root) && is_dir($root)) $failed[] = $root;
}

function bratonien_tools_clear_image_cache_atomic()
{
  global $conf;

  if (!defined('PWG_DERIVATIVE_DIR'))
  {
    throw new RuntimeException('PWG_DERIVATIVE_DIR ist nicht definiert.');
  }

  if (bratonien_tools_main_cache_process_active() || bratonien_tools_main_cache_is_running())
  {
    bratonien_tools_request_main_cache_cancel();
    if (!bratonien_tools_wait_main_cache_stopped(10.0))
    {
      throw new RuntimeException('Der laufende Cache-Aufbau konnte noch nicht beendet werden. Bitte den Abbruch kurz abschließen lassen und erneut leeren.');
    }
  }
  @unlink(bratonien_tools_main_cache_cancel_file());

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

  // Der gesamte bisherige Cache wird in einem einzigen Rename aus dem aktiven
  // Piwigo-Pfad entfernt. Neue Requests schreiben danach nur noch in den neu
  // angelegten Cachebaum und können nicht mit dem Löschen des Altbestands
  // konkurrieren.
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
    // Der aktive Pfad muss in diesem Fehlerfall wiederhergestellt werden.
    if (!file_exists($real_cache_root) && @rename($detached, $real_cache_root))
    {
      throw new RuntimeException('Neuer Bildcache konnte nicht angelegt werden; der bisherige Cache wurde vollständig wiederhergestellt.');
    }
    throw new RuntimeException('Neuer Bildcache konnte nicht angelegt und der bisherige Cache nicht automatisch wiederhergestellt werden. Manueller Eingriff erforderlich.');
  }

  @chmod($real_cache_root, $mode);
  @file_put_contents($real_cache_root.'/index.htm', 'Not allowed!');

  $failed = array();
  bratonien_tools_atomic_cache_remove_tree($detached, $failed);

  $active = bratonien_tools_scan_image_cache($real_cache_root);

  bratonien_tools_write_main_cache_status(array(
    'state'=>'idle',
    'message'=>'Bildcache wurde atomar geleert. Kein manueller Cache-Aufbau aktiv.',
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

  if ($active['files'] > 0)
  {
    $message .= sprintf(
      ' Während bzw. unmittelbar nach dem Umschalten wurden bereits %d neue Derivatdatei(en) durch laufende Anfragen erzeugt; diese gehören zum neuen Cache und sind kein Rest des gelöschten Bestands.',
      $active['files']
    );
  }
  else
  {
    $message .= ' Der neu aktive Cache ist zum Abschluss der Prüfung leer.';
  }

  return array('message'=>$message);
}
