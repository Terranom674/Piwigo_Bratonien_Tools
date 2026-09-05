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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1)
{
  http_response_code(404);
  exit;
}

$table = bratonien_tools_customer_qr_table();
$result = pwg_query('SELECT id, upload_year, code_number, stored_name, mime_type FROM `'.$table.'` WHERE id='.$id.' LIMIT 1');
$row = pwg_db_fetch_assoc($result);
if (!$row)
{
  http_response_code(404);
  exit;
}

$year = (int)$row['upload_year'];
$number = (string)$row['code_number'];
$stored_name = (string)$row['stored_name'];
$mime = strtolower((string)$row['mime_type']);
$allowed_mimes = array('image/png', 'image/jpeg', 'image/webp', 'image/gif');

if (
  $stored_name === ''
  || basename($stored_name) !== $stored_name
  || !in_array($mime, $allowed_mimes, true)
)
{
  http_response_code(404);
  exit;
}

$path = bratonien_tools_customer_qr_storage_root().DIRECTORY_SEPARATOR.$year.DIRECTORY_SEPARATOR.$stored_name;
if (!is_file($path) || !is_readable($path))
{
  http_response_code(404);
  exit;
}

$extension = strtolower((string)pathinfo($stored_name, PATHINFO_EXTENSION));
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';
$filename = 'qr-'.$year.'-'.$number.'.'.$extension;

header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: '.($download ? 'attachment' : 'inline').'; filename="'.$filename.'"');
readfile($path);
exit;
