<?php
$piwigo_root = realpath(dirname(__DIR__, 2));
if ($piwigo_root === false)
{
  http_response_code(500);
  exit;
}
define('PHPWG_ROOT_PATH', rtrim($piwigo_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  http_response_code(404);
  echo json_encode(array('state'=>'unavailable','message'=>'Bratonien Tools ist nicht aktiv.'));
  exit;
}
if (!function_exists('is_admin') || !is_admin())
{
  http_response_code(403);
  echo json_encode(array('state'=>'forbidden','message'=>'Administratorrechte erforderlich.'));
  exit;
}

require_once(BRATONIEN_TOOLS_PATH.'tools/image_cache.inc.php');

function bratonien_tools_status_read_json($file)
{
  if (!is_file($file) || !is_readable($file)) return null;
  $raw = @file_get_contents($file);
  $data = $raw !== false ? json_decode($raw, true) : null;
  return is_array($data) ? $data : null;
}

function bratonien_tools_status_webdav_label(array $status)
{
  $connection = (int)($status['connection_id'] ?? 0);
  $state = (string)($status['state'] ?? 'idle');
  $prefix = $connection > 0 ? 'WebDAV #'.$connection.': ' : 'WebDAV: ';

  if ($state === 'queued') return $prefix.((string)($status['message'] ?? '') !== '' ? (string)$status['message'] : 'Worker wartet auf den Start.');
  if ($state === 'scan')
  {
    $sources = (int)($status['shadow_sources'] ?? 0);
    $selected = (int)($status['selected'] ?? 0);
    $removed = (int)($status['removed_from_index'] ?? 0);
    $created = !empty($status['index_created']);
    return $prefix.'Shadow-Tree wird mit dem eigenen Worker-Index verglichen · '.$sources.' Quellen im Shadow-Tree · '.$selected.' zu verarbeiten'.($removed > 0 ? ' · '.$removed.' gelöschte Quelle(n) aus dem Worker-Index entfernt' : '').($created ? ' · Worker-Index wurde neu angelegt' : '').'.';
  }
  if ($state === 'running')
  {
    $stage = (int)($status['stage'] ?? 0);
    $batch = (int)($status['batch'] ?? 0);
    $requested = (int)($status['batch_requested'] ?? 0);
    $downloaded = (int)($status['batch_downloaded'] ?? 0);
    $completed = (int)($status['stage_completed'] ?? 0);
    $total = (int)($status['selected_total'] ?? 0);
    $parts = array($prefix.'Worker stellt Originale temporär bereit; Piwigo erzeugt die Derivate');
    if ($stage > 0) $parts[] = 'Stufe '.$stage;
    if ($batch > 0) $parts[] = 'Batch '.$batch;
    if ($requested > 0) $parts[] = $downloaded.' / '.$requested.' Quellen dieses Batches geladen';
    if ($total > 0) $parts[] = $completed.' / '.$total.' Quellen dieser Stufe abgeschlossen';
    return implode(' · ', $parts).'.';
  }
  if ($state === 'preempted') return $prefix.'Stufe 2 wurde nach einem vollständigen Batch unterbrochen, damit neue Connector-Inhalte zuerst verarbeitet werden können.';
  if ($state === 'complete') return $prefix.((string)($status['message'] ?? '') !== '' ? (string)$status['message'] : 'Verarbeitung abgeschlossen.');
  if ($state === 'error' || $state === 'fatal') return $prefix.'FEHLER: '.((string)($status['message'] ?? '') !== '' ? (string)$status['message'] : 'WebDAV-Verarbeitung fehlgeschlagen.');
  return $prefix.((string)($status['message'] ?? '') !== '' ? (string)$status['message'] : 'wartet.');
}

$local_file = bratonien_tools_main_cache_status_file();
$local = bratonien_tools_status_read_json($local_file);
if ($local === null)
{
  $local = array(
    'state'=>'idle','message'=>'Noch kein lokaler Piwigo-Cache-Aufbau gestartet.',
    'total'=>0,'completed'=>0,'generated'=>0,'cached'=>0,'skipped'=>0,'errors'=>0,'current'=>'','updated_at'=>0,
  );
}

$webdav = array();
foreach ((array)glob(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.status-*.json') as $file)
{
  $status = bratonien_tools_status_read_json($file);
  if (!$status) continue;
  if (!isset($status['connection_id']) && preg_match('/status-(\d+)\.json$/', $file, $m)) $status['connection_id'] = (int)$m[1];
  $webdav[] = $status;
}
usort($webdav, function($a, $b) {
  return (int)($a['connection_id'] ?? 0) <=> (int)($b['connection_id'] ?? 0);
});

$webdav_active = false;
$webdav_error = false;
$webdav_pending = false;
$webdav_latest = 0;
$webdav_lines = array();
$webdav_all_complete = !empty($webdav);
$webdav_progress_total = 0;
$webdav_progress_completed = 0;
$webdav_source_total = 0;
foreach ($webdav as &$status)
{
  $state = (string)($status['state'] ?? 'idle');
  $updated = (int)($status['updated_at'] ?? 0);
  $webdav_latest = max($webdav_latest, $updated);

  if ($state === 'queued' && $updated > 0 && (time() - $updated) > 45)
  {
    $state = 'error';
    $status['state'] = 'error';
    $status['message'] = 'Worker wurde angefordert, hat aber innerhalb von 45 Sekunden keinen Scan gestartet.';
  }

  if ($state === 'scan')
  {
    $selected = max(0, (int)($status['selected'] ?? 0));
    $webdav_source_total += $selected;
    $webdav_progress_total += $selected * 2;
  }
  elseif ($state === 'running')
  {
    $selected = max(0, (int)($status['selected_total'] ?? 0));
    $stage = max(1, (int)($status['stage'] ?? 1));
    $stage_completed = max(0, min($selected, (int)($status['stage_completed'] ?? 0)));
    $webdav_source_total += $selected;
    $webdav_progress_total += $selected * 2;
    $webdav_progress_completed += $stage >= 2 ? $selected + $stage_completed : $stage_completed;
  }
  elseif ($state === 'complete' || $state === 'error' || $state === 'fatal' || $state === 'preempted')
  {
    $selected = max(0, (int)($status['selected'] ?? 0));
    if ($selected > 0)
    {
      $stage1_completed = max(0, (int)($status['stage1_completed'] ?? 0));
      $stage2_completed = max(0, (int)($status['stage2_completed'] ?? 0));
      $stage1_failed = max(0, (int)($status['stage1_failed'] ?? 0));
      $stage2_failed = max(0, (int)($status['stage2_failed'] ?? 0));
      $webdav_source_total += $selected;
      $webdav_progress_total += $selected * 2;
      $webdav_progress_completed += min($selected * 2, $stage1_completed + $stage2_completed + $stage1_failed + $stage2_failed);
    }
  }

  if (in_array($state, array('queued','scan','running'), true)) $webdav_active = true;
  if ($state === 'preempted') $webdav_pending = true;
  if (in_array($state, array('error','fatal'), true)) $webdav_error = true;
  if ($state !== 'complete') $webdav_all_complete = false;
  $webdav_lines[] = bratonien_tools_status_webdav_label($status);
}
unset($status);

$local_state = (string)($local['state'] ?? 'idle');
$local_updated = (int)($local['updated_at'] ?? 0);
$local_stale = in_array($local_state, array('running','queued'), true) && $local_updated > 0 && (time() - $local_updated) > 45;
if ($local_stale && !$webdav_active)
{
  $local['state'] = 'error';
  $local['message'] = 'Der lokale Piwigo-Teil liefert seit mehr als 45 Sekunden keinen Fortschritt.';
  $local['errors'] = max(1, (int)($local['errors'] ?? 0));
  $local_state = 'error';
}

if ($local_state === 'running' || $local_state === 'queued') $local_label = 'Lokaler Piwigo-Teil läuft: '.((string)($local['message'] ?? '') !== '' ? (string)$local['message'] : 'Cache-Varianten werden verarbeitet.');
elseif ($local_state === 'complete') $local_label = 'Lokaler Piwigo-Teil fertig.';
elseif ($local_state === 'cancelled') $local_label = 'Lokaler Piwigo-Teil wurde abgebrochen.';
elseif ($local_state === 'error') $local_label = 'Lokaler Piwigo-Teil mit Fehler: '.((string)($local['message'] ?? '') !== '' ? (string)$local['message'] : 'unbekannter Fehler');
else $local_label = 'Lokaler Piwigo-Teil: keine relevanten lokalen Bildquellen aktiv.';

$overall = $local;
$overall['local'] = $local;
$overall['webdav'] = $webdav;
$overall['updated_at'] = max($local_updated, $webdav_latest);
$overall['source_total'] = $webdav_source_total;

$local_total = max(0, (int)($local['total'] ?? 0));
$local_completed = max(0, min($local_total, (int)($local['completed'] ?? 0)));
$combined_total = $local_total + $webdav_progress_total;
$combined_completed = $local_completed + min($webdav_progress_total, $webdav_progress_completed);

if ($webdav_active)
{
  $overall['state'] = 'running';
  $overall['total'] = $combined_total;
  $overall['completed'] = $combined_completed;
  $overall['generated'] = (int)($local['generated'] ?? 0);
  $overall['cached'] = (int)($local['cached'] ?? 0);
  $overall['skipped'] = (int)($local['skipped'] ?? 0);
  $overall['message'] = 'Gesamtaufbau läuft'.($webdav_source_total > 0 ? ' · '.$webdav_source_total.' WebDAV-Quellen' : '').'. '.$local_label;
  $overall['current'] = implode(' ', $webdav_lines);
}
elseif ($webdav_error || $local_state === 'error')
{
  $overall['state'] = 'error';
  if ($combined_total > 0)
  {
    $overall['total'] = $combined_total;
    $overall['completed'] = $combined_completed;
  }
  $overall['message'] = 'Cache-Aufbau mit Fehlern'.($webdav_source_total > 0 ? ' · '.$webdav_source_total.' WebDAV-Quellen' : '').'. '.$local_label;
  $overall['current'] = implode(' ', $webdav_lines);
  $overall['errors'] = max(1, (int)($overall['errors'] ?? 0));
}
elseif ($webdav_pending)
{
  $overall['state'] = 'queued';
  $overall['total'] = $combined_total;
  $overall['completed'] = $combined_completed;
  $overall['message'] = 'Cache-Aufbau wartet auf priorisierte Connector-Inhalte'.($webdav_source_total > 0 ? ' · '.$webdav_source_total.' WebDAV-Quellen' : '').'. '.$local_label;
  $overall['current'] = implode(' ', $webdav_lines);
}
elseif ($webdav_all_complete && $local_state === 'complete')
{
  $overall['state'] = 'complete';
  if ($combined_total > 0)
  {
    $overall['total'] = $combined_total;
    $overall['completed'] = $combined_total;
  }
  $overall['message'] = 'Cache-Aufbau abgeschlossen'.($webdav_source_total > 0 ? ' · '.$webdav_source_total.' WebDAV-Quellen' : '').'. '.$local_label;
  $overall['current'] = implode(' ', $webdav_lines);
}
else
{
  // Ein alter complete-Status darf niemals einen neuen Lauf als fertig markieren.
  // Idle/unklare WebDAV-Zustände bleiben sichtbar, bis ein echter Lauf einen
  // aktuellen queued/scan/running/complete/error-Zustand geschrieben hat.
  $overall['state'] = $local_state === 'complete' ? 'idle' : $local_state;
  $overall['message'] = $local_label;
  if ($webdav_lines) $overall['current'] = implode(' ', $webdav_lines);
}

echo json_encode($overall, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
