<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_current_version()
{
  $main = @file_get_contents(BRATONIEN_TOOLS_PATH.'main.inc.php');
  if ($main && preg_match('/Version:\s*([\w.-]+)/i', $main, $m))
  {
    return trim($m[1]);
  }
  return '0.0.0';
}

function bratonien_tools_remote_update_info($force = false)
{
  $cache_key = 'bratonien_self_update_status';
  $cached = function_exists('conf_get_param') ? conf_get_param($cache_key, null) : null;
  if (!$force && !empty($cached))
  {
    $data = json_decode($cached, true);
    if (is_array($data) && !empty($data['checked_at']) && (time() - (int)$data['checked_at']) < 900)
    {
      return $data;
    }
  }

  $current = bratonien_tools_current_version();
  $remote_main = '';
  $ok = function_exists('fetchRemote') && fetchRemote('https://raw.githubusercontent.com/Terranom674/Piwigo_Bratonien_Tools/main/main.inc.php', $remote_main);
  if (!$ok || !preg_match('/Version:\s*([\w.-]+)/i', (string)$remote_main, $m))
  {
    $data = array(
      'checked_at' => time(),
      'current' => $current,
      'remote' => null,
      'update_available' => false,
      'error' => 'GitHub konnte nicht erreicht oder die Versionsnummer nicht gelesen werden.',
    );
  }
  else
  {
    $remote = trim($m[1]);
    $data = array(
      'checked_at' => time(),
      'current' => $current,
      'remote' => $remote,
      'update_available' => version_compare($remote, $current, '>'),
      'error' => null,
    );
  }

  if (function_exists('conf_update_param'))
  {
    conf_update_param($cache_key, json_encode($data));
  }
  return $data;
}

function bratonien_tools_self_update_check()
{
  if (function_exists('is_webmaster') && !is_webmaster())
  {
    throw new RuntimeException('Nur der Webmaster darf Plugin-Updates ausführen.');
  }

  $info = bratonien_tools_remote_update_info(true);
  if (!empty($info['error']))
  {
    throw new RuntimeException($info['error']);
  }

  if (!empty($info['update_available']))
  {
    return array('message' => 'Update verfügbar: '.$info['current'].' → '.$info['remote'].'.');
  }

  return array('message' => 'Bratonien Tools ist aktuell (Version '.$info['current'].').');
}

function bratonien_tools_self_update_run()
{
  global $template;

  if (function_exists('is_webmaster') && !is_webmaster())
  {
    throw new RuntimeException('Nur der Webmaster darf Plugin-Updates ausführen.');
  }
  if (!class_exists('ZipArchive'))
  {
    throw new RuntimeException('PHP ZipArchive ist nicht verfügbar. Das automatische Update kann so nicht entpackt werden.');
  }
  if (!is_writable(dirname(rtrim(BRATONIEN_TOOLS_PATH, '/'))))
  {
    throw new RuntimeException('Der Piwigo-Pluginordner ist für den Webserver nicht beschreibbar.');
  }

  $info = bratonien_tools_remote_update_info(true);
  if (!empty($info['error']))
  {
    throw new RuntimeException($info['error']);
  }
  if (empty($info['update_available']))
  {
    return array('message' => 'Kein Update nötig. Installiert ist bereits Version '.$info['current'].'.');
  }

  $work_root = rtrim(PHPWG_ROOT_PATH, '/').'/_data/bratonien-updater';
  if (!is_dir($work_root) && !@mkdir($work_root, 0755, true))
  {
    throw new RuntimeException('Temporäres Update-Verzeichnis konnte nicht angelegt werden.');
  }

  $run_dir = $work_root.'/'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
  if (!@mkdir($run_dir, 0755, true))
  {
    throw new RuntimeException('Temporäres Update-Verzeichnis konnte nicht angelegt werden.');
  }

  $zip_data = '';
  if (!function_exists('fetchRemote') || !fetchRemote('https://codeload.github.com/Terranom674/Piwigo_Bratonien_Tools/zip/refs/heads/main', $zip_data) || strlen((string)$zip_data) < 1000)
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Das Update-Archiv konnte nicht von GitHub geladen werden.');
  }

  $zip_file = $run_dir.'/update.zip';
  if (@file_put_contents($zip_file, $zip_data) === false)
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Das Update-Archiv konnte nicht gespeichert werden.');
  }

  $extract_dir = $run_dir.'/extract';
  @mkdir($extract_dir, 0755, true);
  $zip = new ZipArchive();
  if ($zip->open($zip_file) !== true || !$zip->extractTo($extract_dir))
  {
    if ($zip instanceof ZipArchive) { @$zip->close(); }
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Das Update-Archiv konnte nicht entpackt werden.');
  }
  $zip->close();

  $source = $extract_dir.'/Piwigo_Bratonien_Tools-main';
  $source_main = $source.'/main.inc.php';
  if (!is_file($source_main))
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Das geladene Archiv enthält kein gültiges Bratonien-Tools-Plugin.');
  }

  $remote_main = @file_get_contents($source_main);
  if (!$remote_main || !preg_match('/Plugin Name:\s*Bratonien Tools/i', $remote_main) || !preg_match('/Version:\s*([\w.-]+)/i', $remote_main, $vm))
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Die geladene Plugin-Version konnte nicht verifiziert werden.');
  }
  $package_version = trim($vm[1]);
  if ($package_version !== $info['remote'])
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Versionsprüfung fehlgeschlagen: Erwartet '.$info['remote'].', erhalten '.$package_version.'.');
  }

  $plugin_dir = rtrim(BRATONIEN_TOOLS_PATH, '/');
  $backup_root = rtrim(PHPWG_ROOT_PATH, '/').'/_data/bratonien-plugin-backups';
  if (!is_dir($backup_root) && !@mkdir($backup_root, 0755, true))
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Backup-Verzeichnis konnte nicht angelegt werden.');
  }
  $backup_dir = $backup_root.'/'.basename($plugin_dir).'-'.$info['current'].'-'.date('Ymd-His');

  if (!@rename($plugin_dir, $backup_dir))
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Die bestehende Plugin-Version konnte nicht gesichert werden.');
  }

  if (!@rename($source, $plugin_dir))
  {
    @rename($backup_dir, $plugin_dir);
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Die neue Plugin-Version konnte nicht aktiviert werden. Das Backup wurde wiederhergestellt.');
  }

  try
  {
    if (defined('PLUGINS_TABLE'))
    {
      pwg_query("UPDATE ".PLUGINS_TABLE." SET version='".pwg_db_real_escape_string($package_version)."' WHERE id='".pwg_db_real_escape_string(BRATONIEN_TOOLS_ID)."'");
    }

    if (function_exists('conf_delete_param'))
    {
      conf_delete_param('bratonien_self_update_status');
    }
    elseif (function_exists('conf_update_param'))
    {
      conf_update_param('bratonien_self_update_status', '');
    }

    if (is_object($template) && method_exists($template, 'delete_compiled_templates'))
    {
      $template->delete_compiled_templates();
    }
  }
  catch (Throwable $e)
  {
    // The files are already updated. Keep them and report the post-update issue.
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Plugin-Dateien wurden aktualisiert, aber die Nachbereitung meldet: '.$e->getMessage());
  }

  bratonien_tools_self_update_rrmdir($run_dir);
  return array('message' => 'Bratonien Tools wurde auf Version '.$package_version.' aktualisiert. Backup: '.$backup_dir);
}

function bratonien_tools_self_update_rrmdir($path)
{
  if (!is_dir($path)) return;
  $items = scandir($path);
  foreach ($items as $item)
  {
    if ($item === '.' || $item === '..') continue;
    $full = $path.'/'.$item;
    if (is_dir($full) && !is_link($full)) bratonien_tools_self_update_rrmdir($full);
    else @unlink($full);
  }
  @rmdir($path);
}
