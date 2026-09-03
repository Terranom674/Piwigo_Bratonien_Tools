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
foreach ($webdav as $status)
{
  $state = (string)($status['state'] ?? 'idle');
  $webdav_latest = max($webdav_latest, (int)($status['updated_at'] ?? 0));
  if (in_array($state, array('scan','running'), true)) $webdav_active = true;
  if ($state === 'preempted') $webdav_pending = true;
  if (in_array($state, array('error','fatal'), true)) $webdav_error = true;
  $webdav_lines[] = bratonien_tools_status_webdav_label($status);
}

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

if ($webdav_active)
{
  $overall['state'] = 'running';
  $overall['total'] = 0;
  $overall['completed'] = 0;
  $overall['generated'] = 0;
  $overall['cached'] = 0;
  $overall['skipped'] = 0;
  $overall['message'] = 'Gesamtaufbau läuft. '.$local_label;
  $overall['current'] = implode(' ', $webdav_lines);
}
elseif ($webdav_error || $local_state === 'error')
{
  $overall['state'] = 'error';
  $overall['message'] = 'Cache-Aufbau mit Fehlern. '.$local_label;
  $overall['current'] = implode(' ', $webdav_lines);
  $overall['errors'] = max(1, (int)($overall['errors'] ?? 0));
}
elseif ($webdav_pending)
{
  $overall['state'] = 'queued';
  $overall['total'] = 0;
  $overall['completed'] = 0;
  $overall['message'] = 'Cache-Aufbau wartet auf priorisierte Connector-Inhalte. '.$local_label;
  $overall['current'] = implode(' ', $webdav_lines);
}
elseif ($webdav && $local_state === 'complete')
{
  $overall['state'] = 'complete';
  $overall['message'] = 'Cache-Aufbau abgeschlossen. '.$local_label;
  $overall['current'] = implode(' ', $webdav_lines);
}
else
{
  $overall['message'] = $local_label;
  if ($webdav_lines) $overall['current'] = implode(' ', $webdav_lines);
}

echo json_encode($overall, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
