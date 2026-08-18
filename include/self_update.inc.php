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

function bratonien_tools_self_update_fetch_text($url, &$body, &$details)
{
  $body = '';
  $details = '';

  if (function_exists('curl_init'))
  {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 3,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_USERAGENT => 'Bratonien-Tools-Updater/'.bratonien_tools_current_version(),
      CURLOPT_HTTPHEADER => array('Accept: application/vnd.github+json, text/plain;q=0.9, */*;q=0.8'),
    ));
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $errno !== 0)
    {
      $details = 'cURL-Fehler '.$errno.': '.$error;
      return false;
    }
    if ($http < 200 || $http >= 300)
    {
      $details = 'HTTP '.$http;
      return false;
    }

    $body = (string)$response;
    return true;
  }

  if (function_exists('fetchRemote') && fetchRemote($url, $body))
  {
    return true;
  }

  $details = 'Remote-Inhalt konnte nicht geladen werden.';
  return false;
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
  $commit_json = '';
  $details = '';
  $commit_url = 'https://api.github.com/repos/Terranom674/Piwigo_Bratonien_Tools/commits/main';

  if (!bratonien_tools_self_update_fetch_text($commit_url, $commit_json, $details))
  {
    $data = array(
      'checked_at' => time(),
      'current' => $current,
      'remote' => null,
      'signature' => null,
      'main_sha256' => null,
      'update_available' => false,
      'error' => 'GitHub konnte nicht erreicht oder der aktuelle Commit nicht ermittelt werden.'.($details !== '' ? ' Details: '.$details : ''),
    );
  }
  else
  {
    $commit = json_decode($commit_json, true);
    $signature = is_array($commit) ? strtolower(trim((string)($commit['sha'] ?? ''))) : '';
    if (!preg_match('/^[a-f0-9]{40}$/', $signature))
    {
      $data = array(
        'checked_at' => time(),
        'current' => $current,
        'remote' => null,
        'signature' => null,
        'main_sha256' => null,
        'update_available' => false,
        'error' => 'GitHub lieferte keine gültige Commit-Signatur für main.',
      );
    }
    else
    {
      $remote_main = '';
      $raw_url = 'https://raw.githubusercontent.com/Terranom674/Piwigo_Bratonien_Tools/'.$signature.'/main.inc.php';
      if (!bratonien_tools_self_update_fetch_text($raw_url, $remote_main, $details) || !preg_match('/Version:\s*([\w.-]+)/i', (string)$remote_main, $m))
      {
        $data = array(
          'checked_at' => time(),
          'current' => $current,
          'remote' => null,
          'signature' => $signature,
          'main_sha256' => null,
          'update_available' => false,
          'error' => 'Die Plugin-Version des ermittelten GitHub-Commits konnte nicht gelesen werden.'.($details !== '' ? ' Details: '.$details : ''),
        );
      }
      else
      {
        $remote = trim($m[1]);
        $data = array(
          'checked_at' => time(),
          'current' => $current,
          'remote' => $remote,
          'signature' => $signature,
          'main_sha256' => hash('sha256', (string)$remote_main),
          'update_available' => version_compare($remote, $current, '>'),
          'error' => null,
        );
      }
    }
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

  $signature_label = !empty($info['signature']) ? substr($info['signature'], 0, 12) : 'unbekannt';
  if (!empty($info['update_available']))
  {
    return array(
      'message' => 'Update verfügbar: '.$info['current'].' → '.$info['remote'].' · Signatur '.$signature_label.'.',
      'self_update' => $info,
    );
  }

  return array(
    'message' => 'Bratonien Tools ist aktuell (Version '.$info['current'].', Signatur '.$signature_label.').',
    'self_update' => $info,
  );
}

function bratonien_tools_download_update_archive($url, &$data, &$details)
{
  $data = '';
  $details = '';

  if (function_exists('curl_init'))
  {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_CONNECTTIMEOUT => 15,
      CURLOPT_TIMEOUT => 60,
      CURLOPT_USERAGENT => 'Bratonien-Tools-Updater/'.bratonien_tools_current_version(),
      CURLOPT_HTTPHEADER => array('Accept: application/zip, application/octet-stream;q=0.9, */*;q=0.8'),
    ));

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effective_url = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($response !== false)
    {
      $data = (string)$response;
    }

    if ($errno !== 0)
    {
      $details = 'cURL-Fehler '.$errno.': '.$error;
      return false;
    }

    if ($http_code < 200 || $http_code >= 300)
    {
      $details = 'HTTP '.$http_code;
      if ($content_type !== '')
      {
        $details .= ', Content-Type '.$content_type;
      }
      if ($effective_url !== '' && $effective_url !== $url)
      {
        $details .= ', Ziel '.$effective_url;
      }
      return false;
    }

    if (strlen($data) < 1000)
    {
      $details = 'Antwort war ungewöhnlich klein ('.strlen($data).' Byte)';
      if ($content_type !== '')
      {
        $details .= ', Content-Type '.$content_type;
      }
      return false;
    }

    return true;
  }

  if (!function_exists('fetchRemote'))
  {
    $details = 'Weder cURL noch Piwigos fetchRemote() sind verfügbar.';
    return false;
  }

  $ok = fetchRemote($url, $data);
  if (!$ok)
  {
    $details = 'Piwigos fetchRemote() hat den Download ohne weitere Fehlerdetails abgebrochen.';
    return false;
  }

  if (strlen((string)$data) < 1000)
  {
    $details = 'Piwigos fetchRemote() lieferte nur '.strlen((string)$data).' Byte.';
    return false;
  }

  return true;
}

function bratonien_tools_self_update_find_source($extract_dir, $signature)
{
  $expected = rtrim($extract_dir, '/').'/Piwigo_Bratonien_Tools-'.$signature;
  if (is_dir($expected))
  {
    return $expected;
  }

  $candidates = glob(rtrim($extract_dir, '/').'/Piwigo_Bratonien_Tools-*', GLOB_ONLYDIR);
  if (is_array($candidates) && count($candidates) === 1)
  {
    return $candidates[0];
  }

  return '';
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
    return array(
      'message' => 'Kein Update nötig. Installiert ist bereits Version '.$info['current'].'.',
      'self_update' => $info,
    );
  }

  $signature = strtolower(trim((string)($info['signature'] ?? '')));
  $expected_main_sha256 = strtolower(trim((string)($info['main_sha256'] ?? '')));
  if (!preg_match('/^[a-f0-9]{40}$/', $signature) || !preg_match('/^[a-f0-9]{64}$/', $expected_main_sha256))
  {
    throw new RuntimeException('Update abgebrochen: Version oder Signatur des Zielstands ist unvollständig.');
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
  $download_details = '';
  $archive_url = 'https://codeload.github.com/Terranom674/Piwigo_Bratonien_Tools/zip/'.$signature;
  if (!bratonien_tools_download_update_archive($archive_url, $zip_data, $download_details))
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException(
      'Das Update-Archiv konnte nicht von GitHub geladen werden.'
      .($download_details !== '' ? ' Details: '.$download_details : '')
    );
  }

  $zip_file = $run_dir.'/update.zip';
  if (@file_put_contents($zip_file, $zip_data) === false)
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Das Update-Archiv konnte nicht gespeichert werden. Ziel: '.$zip_file);
  }

  $extract_dir = $run_dir.'/extract';
  @mkdir($extract_dir, 0755, true);
  $zip = new ZipArchive();
  $zip_open_result = $zip->open($zip_file);
  if ($zip_open_result !== true || !$zip->extractTo($extract_dir))
  {
    if ($zip instanceof ZipArchive) { @$zip->close(); }
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Das Update-Archiv konnte nicht entpackt werden. ZipArchive-Code: '.var_export($zip_open_result, true).'.');
  }
  $zip->close();

  $source = bratonien_tools_self_update_find_source($extract_dir, $signature);
  $source_main = $source !== '' ? $source.'/main.inc.php' : '';
  if ($source === '' || !is_file($source_main))
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Das geladene Archiv enthält kein gültiges Bratonien-Tools-Plugin für die erwartete Signatur '.substr($signature, 0, 12).'.');
  }

  $remote_main = @file_get_contents($source_main);
  if (!$remote_main || !preg_match('/Plugin Name:\s*Bratonien Tools/i', $remote_main) || !preg_match('/Version:\s*([\w.-]+)/i', $remote_main, $vm))
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Die geladene Plugin-Version konnte nicht verifiziert werden. main.inc.php fehlt oder enthält keine lesbare Plugin-/Versionsangabe.');
  }

  $package_version = trim($vm[1]);
  $package_main_sha256 = hash('sha256', (string)$remote_main);
  if (!hash_equals($expected_main_sha256, $package_main_sha256))
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Signaturprüfung fehlgeschlagen: Das geladene Paket gehört nicht exakt zum zuvor geprüften GitHub-Stand '.substr($signature, 0, 12).'.');
  }
  if ($package_version !== $info['remote'])
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Versionsprüfung fehlgeschlagen: Erwartet '.$info['remote'].', erhalten '.$package_version.'; Signatur '.substr($signature, 0, 12).'.');
  }

  $plugin_dir = rtrim(BRATONIEN_TOOLS_PATH, '/');
  $backup_root = rtrim(PHPWG_ROOT_PATH, '/').'/_data/bratonien-plugin-backups';
  if (!is_dir($backup_root) && !@mkdir($backup_root, 0755, true))
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Backup-Verzeichnis konnte nicht angelegt werden: '.$backup_root);
  }
  $backup_dir = $backup_root.'/'.basename($plugin_dir).'-'.$info['current'].'-'.date('Ymd-His');

  if (!@rename($plugin_dir, $backup_dir))
  {
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Die bestehende Plugin-Version konnte nicht gesichert werden. Quelle: '.$plugin_dir.'; Ziel: '.$backup_dir);
  }

  if (!@rename($source, $plugin_dir))
  {
    @rename($backup_dir, $plugin_dir);
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Die neue Plugin-Version konnte nicht aktiviert werden. Das Backup wurde wiederhergestellt. Quelle: '.$source.'; Ziel: '.$plugin_dir);
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
    bratonien_tools_self_update_rrmdir($run_dir);
    throw new RuntimeException('Plugin-Dateien wurden aktualisiert, aber die Nachbereitung meldet: '.$e->getMessage());
  }

  bratonien_tools_self_update_rrmdir($run_dir);
  $updated_info = array(
    'checked_at' => time(),
    'current' => $package_version,
    'remote' => $package_version,
    'signature' => $signature,
    'main_sha256' => $package_main_sha256,
    'update_available' => false,
    'error' => null,
  );
  return array(
    'message' => 'Bratonien Tools wurde auf Version '.$package_version.' aktualisiert. Signatur '.substr($signature, 0, 12).'. Backup: '.$backup_dir,
    'self_update' => $updated_info,
  );
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
