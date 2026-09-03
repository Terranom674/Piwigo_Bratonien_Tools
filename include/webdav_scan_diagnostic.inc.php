<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_run_webdav_scan_diagnostic()
{
  if (!function_exists('exec')) throw new RuntimeException('PHP exec() ist deaktiviert; der Scan-Test kann nicht gestartet werden.');
  if (!function_exists('bratonien_tools_nc_connector_connections') || !function_exists('bratonien_tools_nc_connector_is_webdav'))
  {
    throw new RuntimeException('Connector-Verbindungen koennen nicht gelesen werden.');
  }

  $php = function_exists('bratonien_tools_webdav_warmup_php_cli') ? bratonien_tools_webdav_warmup_php_cli() : null;
  if (!$php) throw new RuntimeException('PHP-CLI wurde fuer den Scan-Test nicht gefunden.');

  $script = realpath(BRATONIEN_TOOLS_PATH.'runtime/lib/webdav-scan-diagnostic.php');
  if (!$script || !is_file($script)) throw new RuntimeException('WebDAV-Scan-Diagnose wurde nicht gefunden.');

  $reports = array();
  $failed = false;
  foreach (bratonien_tools_nc_connector_connections() as $connection)
  {
    if (empty($connection['enabled']) || !bratonien_tools_nc_connector_is_webdav($connection)) continue;
    $connection_id = (int)($connection['id'] ?? 0);
    if ($connection_id < 1) continue;

    $output = array();
    $exit = 1;
    @exec(
      escapeshellarg($php).' '.escapeshellarg($script).' --connection-id='.$connection_id.' 2>&1',
      $output,
      $exit
    );
    $text = trim(implode("\n", $output));
    $decoded = $text !== '' ? json_decode($text, true) : null;
    if (!is_array($decoded))
    {
      $decoded = array(
        'ok'=>false,
        'connection_id'=>$connection_id,
        'fatal'=>'Diagnose lieferte kein gueltiges JSON.',
        'raw'=>$text,
      );
    }
    $decoded['exit_code'] = (int)$exit;
    $reports[] = $decoded;
    if ($exit !== 0 || empty($decoded['ok'])) $failed = true;
  }

  if (!$reports) throw new RuntimeException('Keine aktive WebDAV-Verbindung gefunden.');

  $parts = array();
  foreach ($reports as $report)
  {
    $id = (int)($report['connection_id'] ?? 0);
    if (!empty($report['fatal']))
    {
      $parts[] = 'WebDAV #'.$id.': FEHLER: '.(string)$report['fatal'].' (Exit '.(int)$report['exit_code'].')';
      continue;
    }

    $lock_state = (string)($report['worker_lock_state'] ?? 'unknown');
    if ($lock_state === 'free') $lock_text = 'Worker-Lock frei';
    elseif ($lock_state === 'busy') $lock_text = 'Worker-Lock BELEGT';
    elseif ($lock_state === 'open_failed') $lock_text = 'Worker-Lock NICHT OEFFENBAR';
    else $lock_text = 'Worker-Lock unbekannt';

    $parts[] = sprintf(
      'WebDAV #%d: Mapping %d Dateien, Shadow %d Links, %d passend, %d defekt, %d ohne Mapping, %d doppelt; %s%s (Exit %d).',
      $id,
      (int)($report['mapping_files'] ?? 0),
      (int)($report['shadow_links'] ?? 0),
      (int)($report['matched'] ?? 0),
      (int)($report['broken'] ?? 0),
      (int)($report['unmapped'] ?? 0),
      (int)($report['duplicates'] ?? 0),
      $lock_text,
      !empty($report['worker_lock_detail']) ? ' - '.(string)$report['worker_lock_detail'] : '',
      (int)$report['exit_code']
    );
  }

  return array(
    'message'=>($failed ? 'Scan-/Lock-Test auffaellig. ' : 'Scan-/Lock-Test erfolgreich. ').implode(' ', $parts),
  );
}
