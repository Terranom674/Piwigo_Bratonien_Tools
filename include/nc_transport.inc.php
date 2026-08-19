<?php

function bratonien_tools_nc_transport_host($url)
{
  $host = trim((string)parse_url((string)$url, PHP_URL_HOST));
  if ($host === '') throw new RuntimeException('Die Nextcloud-Adresse enthält keinen gültigen Hostnamen oder keine IP-Adresse.');
  return trim($host, '[]');
}

function bratonien_tools_nc_transport_scheme($url)
{
  $scheme = strtolower(trim((string)parse_url((string)$url, PHP_URL_SCHEME)));
  if (!in_array($scheme, array('http','https'), true)) throw new RuntimeException('Nextcloud muss per HTTP oder HTTPS angesprochen werden.');
  return $scheme;
}

function bratonien_tools_nc_transport_is_ip($host)
{
  return filter_var(trim((string)$host, '[]'), FILTER_VALIDATE_IP) !== false;
}

function bratonien_tools_nc_transport_public_ip($host)
{
  static $cache = array();

  $host = strtolower(trim((string)$host, '[]'));
  if ($host === '') throw new RuntimeException('Für die Nextcloud-Verbindung fehlt der Hostname.');
  if (bratonien_tools_nc_transport_is_ip($host)) return $host;
  if (isset($cache[$host])) return $cache[$host];
  if (!function_exists('curl_init')) throw new RuntimeException('Der öffentliche DNS-Abgleich benötigt PHP-cURL.');

  $providers = array(
    array('host'=>'dns.google', 'ips'=>array('8.8.8.8','8.8.4.4'), 'url'=>'https://dns.google/resolve?name='.rawurlencode($host).'&type=A'),
    array('host'=>'cloudflare-dns.com', 'ips'=>array('1.1.1.1','1.0.0.1'), 'url'=>'https://cloudflare-dns.com/dns-query?name='.rawurlencode($host).'&type=A'),
  );

  foreach ($providers as $provider)
  {
    foreach ($provider['ips'] as $resolver_ip)
    {
      $ch = curl_init($provider['url']);
      $options = array(
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>array('Accept: application/dns-json'),
        CURLOPT_USERAGENT=>'Bratonien-Tools-DNS/0.9.7.1',
      );
      if (defined('CURLOPT_RESOLVE'))
      {
        $options[CURLOPT_RESOLVE] = array($provider['host'].':443:'.$resolver_ip);
      }
      curl_setopt_array($ch, $options);
      $body = curl_exec($ch);
      $errno = curl_errno($ch);
      $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($body === false || $errno !== 0 || $status < 200 || $status >= 300) continue;

      $decoded = json_decode((string)$body, true);
      if (!is_array($decoded) || !isset($decoded['Answer']) || !is_array($decoded['Answer'])) continue;
      foreach ($decoded['Answer'] as $answer)
      {
        if ((int)($answer['type'] ?? 0) !== 1) continue;
        $ip = trim((string)($answer['data'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) continue;
        return $cache[$host] = $ip;
      }
    }
  }

  throw new RuntimeException('Für '.$host.' konnte keine öffentliche IPv4-Adresse ermittelt werden.');
}

function bratonien_tools_nc_transport_resolve_entry($url)
{
  $scheme = bratonien_tools_nc_transport_scheme($url);
  $host = bratonien_tools_nc_transport_host($url);
  if (bratonien_tools_nc_transport_is_ip($host)) return null;

  $port = (int)parse_url((string)$url, PHP_URL_PORT);
  if ($port < 1) $port = $scheme === 'https' ? 443 : 80;
  $ip = bratonien_tools_nc_transport_public_ip($host);
  return $host.':'.$port.':'.$ip;
}

function bratonien_tools_nc_transport_apply_curl(array &$options, $url)
{
  $entry = bratonien_tools_nc_transport_resolve_entry($url);
  if ($entry !== null)
  {
    if (!defined('CURLOPT_RESOLVE')) throw new RuntimeException('Diese cURL-Version unterstützt keine direkte Host-zu-IP-Zuordnung.');
    $options[CURLOPT_RESOLVE] = array($entry);
  }
}
