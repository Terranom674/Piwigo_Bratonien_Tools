<?php
if (PHP_SAPI !== 'cli')
{
  http_response_code(404);
  exit;
}

$query = '';
foreach ($argv as $arg)
{
  if (strpos($arg, '--query=') === 0)
  {
    $query = substr($arg, strlen('--query='));
  }
}
if ($query === '')
{
  fwrite(STDERR, "Parameter --query fehlt.\n");
  exit(2);
}

$piwigo_root = realpath(dirname(__DIR__, 4));
if ($piwigo_root === false)
{
  fwrite(STDERR, "Piwigo-Root wurde nicht gefunden.\n");
  exit(3);
}

parse_str($query, $_GET);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/plugins/bratonien_tools/watermark.php?'.$query;
$_SERVER['SCRIPT_NAME'] = '/plugins/bratonien_tools/watermark.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['QUERY_STRING'] = $query;
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-Presentation-Refresh/0.9.7.1.41';
$_SERVER['HTTPS'] = 'off';

chdir($piwigo_root);
include $piwigo_root.'/plugins/bratonien_tools/watermark.php';
