<?php
$piwigo_root = realpath(dirname(__DIR__, 2));
if ($piwigo_root === false)
{
  http_response_code(500);
  exit;
}

define('PHPWG_ROOT_PATH', rtrim($piwigo_root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

if (!defined('BRATONIEN_TOOLS_PATH'))
{
  http_response_code(404);
  exit;
}

check_status(ACCESS_ADMINISTRATOR);
require_once(BRATONIEN_TOOLS_PATH.'include/customer_qr_upload.inc.php');

if (!class_exists('ZipArchive'))
{
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  exit('ZIP-Unterstützung ist auf diesem Server nicht verfügbar.');
}

bratonien_tools_customer_qr_ensure_storage();
$limits = bratonien_tools_customer_qr_year_limits();
$table = bratonien_tools_customer_qr_table();
$requested_year = isset($_GET['year']) && $_GET['year'] !== '' ? bratonien_tools_customer_qr_year($_GET['year']) : 0;

$query = 'SELECT upload_year, code_number, stored_name FROM `'.$table.'`';
if ($requested_year > 0)
{
  $query .= ' WHERE upload_year='.(int)$requested_year;
}
$query .= ' ORDER BY upload_year ASC, CAST(code_number AS UNSIGNED) ASC, id ASC';

$result = pwg_query($query);
$files = array();
$root = bratonien_tools_customer_qr_storage_root();
while ($row = pwg_db_fetch_assoc($result))
{
  $year = (int)$row['upload_year'];
  if (!isset($limits[$year]))
  {
    continue;
  }

  $number = bratonien_tools_customer_qr_number_for_year($year, $row['code_number']);
  $stored_name = (string)$row['stored_name'];
  if ($stored_name === '' || basename($stored_name) !== $stored_name)
  {
    continue;
  }

  $path = $root.DIRECTORY_SEPARATOR.$year.DIRECTORY_SEPARATOR.$stored_name;
  if (!is_file($path) || !is_readable($path))
  {
    continue;
  }

  $extension = strtolower((string)pathinfo($stored_name, PATHINFO_EXTENSION));
  if (!in_array($extension, array('png', 'jpg', 'jpeg', 'webp', 'gif'), true))
  {
    continue;
  }

  $width = max(2, strlen((string)$limits[$year]));
  $archive_name = 'qr-'.str_pad($number, $width, '0', STR_PAD_LEFT).'.'.$extension;
  if ($requested_year === 0)
  {
    $archive_name = $year.'/'.$archive_name;
  }

  $files[] = array(
    'path' => $path,
    'archive_name' => $archive_name,
  );
}

if (empty($files))
{
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  exit('Keine QR-Codes für den Batch-Download vorhanden.');
}

$temp = tempnam(sys_get_temp_dir(), 'bratonien-qr-');
if ($temp === false)
{
  http_response_code(500);
  exit;
}

$zip = new ZipArchive();
if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
{
  @unlink($temp);
  http_response_code(500);
  exit;
}

foreach ($files as $file)
{
  $zip->addFile($file['path'], $file['archive_name']);
}
$zip->close();

$download_name = $requested_year > 0
  ? 'qr-codes-'.$requested_year.'.zip'
  : 'qr-codes-alle-jahre.zip';

header('Content-Type: application/zip');
header('Content-Length: '.filesize($temp));
header('Content-Disposition: attachment; filename="'.$download_name.'"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($temp);
@unlink($temp);
exit;
