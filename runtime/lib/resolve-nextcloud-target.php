#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli')
{
  fwrite(STDERR, "CLI only\n");
  exit(1);
}

require_once(dirname(__DIR__, 2).'/include/nc_transport.inc.php');

try
{
  if ($argc !== 2) throw new RuntimeException('Aufruf: resolve-nextcloud-target.php <nextcloud-url>');
  $url = rtrim(trim((string)$argv[1]), '/');
  bratonien_tools_nc_transport_scheme($url);
  $host = bratonien_tools_nc_transport_host($url);
  echo bratonien_tools_nc_transport_public_ip($host)."\n";
  exit(0);
}
catch (Throwable $e)
{
  fwrite(STDERR, $e->getMessage()."\n");
  exit(1);
}
