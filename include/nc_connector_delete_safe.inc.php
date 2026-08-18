<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Delete a connector connection from Piwigo without depending on the web
 * server being allowed to write into root-owned runtime directories.
 *
 * Runtime files are removed by runtime/cleanup-stale.php on the next runner
 * cycle before any connector sync is started.
 */
function bratonien_tools_nc_connector_delete_safe()
{
  $id = (int)($_POST['connection_id'] ?? 0);
  $connection = bratonien_tools_nc_connector_connection($id, false);
  if (!$connection)
  {
    throw new RuntimeException('Connector-Verbindung wurde nicht gefunden.');
  }

  $table = bratonien_tools_nc_connector_table();
  pwg_query("DELETE FROM `$table` WHERE id=".$id." LIMIT 1");

  // Public status data is only cache/runtime information. Remove it when PHP
  // has permission, but never make successful DB deletion depend on ownership
  // of files previously created by the root-run connector service.
  $status_dir = rtrim(PHPWG_ROOT_PATH, '/').'/_data/bratonien-tools/nc-connector-status';
  $public_status = $status_dir.'/connection-'.$id.'.json';
  if (is_file($public_status))
  {
    @unlink($public_status);
  }

  // Keep the old tombstone mechanism as a best-effort fast path for systems
  // where the web server can write here. cleanup-stale.php is the authoritative
  // fallback and does not require this file.
  if (is_dir($status_dir) || @mkdir($status_dir, 0755, true))
  {
    @file_put_contents($status_dir.'/deleted-'.$id, date('c')."\n", LOCK_EX);
  }

  return array(
    'message'=>'Connector-Verbindung wurde gelöscht. Verbliebene Laufzeitdateien werden vor dem nächsten Connector-Lauf automatisch entfernt. Nextcloud- und Piwigo-Bilder blieben unverändert.',
  );
}
