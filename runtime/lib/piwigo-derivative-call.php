<?php
if (PHP_SAPI !== 'cli')
{
  http_response_code(404);
  exit;
}

$request = '';
foreach ($argv as $arg)
{
  if (strpos($arg, '--request=') === 0)
  {
    $request = substr($arg, strlen('--request='));
  }
}
if ($request === '' || strpos($request, '/i.php?/') !== 0)
{
  fwrite(STDERR, "Ungültige Piwigo-Derivatanforderung.\n");
  exit(2);
}

$piwigo_root = realpath(dirname(__DIR__, 4));
if ($piwigo_root === false || !is_file($piwigo_root.'/i.php'))
{
  fwrite(STDERR, "Piwigo i.php wurde nicht gefunden.\n");
  exit(3);
}

chdir($piwigo_root);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $request;
$_SERVER['SCRIPT_NAME'] = '/i.php';
$_SERVER['PHP_SELF'] = '/i.php';
$_SERVER['QUERY_STRING'] = ltrim((string)parse_url($request, PHP_URL_QUERY), '?');
$_SERVER['HTTP_USER_AGENT'] = 'Bratonien-WebDAV-Warmup-Piwigo-Caller/0.9.7.1.8';
$_SERVER['HTTPS'] = 'off';

// Piwigo selbst erzeugt das Derivat. Der Warmup benötigt den ausgelieferten
// Bild-Body nicht und prüft danach ausschließlich Piwigos Cache-Datei.
ob_start(function ($buffer) { return ''; }, 1);
include $piwigo_root.'/i.php';
