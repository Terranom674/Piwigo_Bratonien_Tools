<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_webdav_warmup_settings_file()
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-webdav-warmup.settings.json';
}

function bratonien_tools_get_webdav_warmup_settings()
{
  $settings = array(
    'enabled'=>true,
    'batch_size'=>10,
    'periodic_hours'=>12,
    'piwigo_base_url'=>'http://127.0.0.1',
  );

  $file = bratonien_tools_webdav_warmup_settings_file();
  if (is_file($file) && is_readable($file))
  {
    $raw = @file_get_contents($file);
    $saved = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($saved))
    {
      if (array_key_exists('enabled', $saved)) $settings['enabled'] = (bool)$saved['enabled'];
      if (isset($saved['batch_size'])) $settings['batch_size'] = max(1, min(50, (int)$saved['batch_size']));
      if (isset($saved['periodic_hours'])) $settings['periodic_hours'] = max(1, min(168, (int)$saved['periodic_hours']));
      if (!empty($saved['piwigo_base_url'])) $settings['piwigo_base_url'] = rtrim((string)$saved['piwigo_base_url'], '/');
    }
  }
  return $settings;
}

function bratonien_tools_save_webdav_warmup_settings()
{
  $current = bratonien_tools_get_webdav_warmup_settings();
  $enabled = !empty($_POST['webdav_warmup_enabled']);
  $batch_size = isset($_POST['webdav_warmup_batch_size']) ? (int)$_POST['webdav_warmup_batch_size'] : (int)$current['batch_size'];
  $periodic_hours = isset($_POST['webdav_warmup_periodic_hours']) ? (int)$_POST['webdav_warmup_periodic_hours'] : (int)$current['periodic_hours'];
  $batch_size = max(1, min(50, $batch_size));
  $periodic_hours = max(1, min(168, $periodic_hours));

  $payload = array(
    'enabled'=>$enabled,
    'batch_size'=>$batch_size,
    'periodic_hours'=>$periodic_hours,
    'piwigo_base_url'=>(string)$current['piwigo_base_url'],
  );
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('Warmup-Einstellungen konnten nicht serialisiert werden.');

  $file = bratonien_tools_webdav_warmup_settings_file();
  $directory = dirname($file);
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory))
  {
    throw new RuntimeException('Warmup-Einstellungsverzeichnis konnte nicht angelegt werden.');
  }
  $tmp = $file.'.tmp-'.bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, $json."\n", LOCK_EX) === false)
  {
    throw new RuntimeException('Warmup-Einstellungen konnten nicht gespeichert werden.');
  }
  @chmod($tmp, 0664);
  if (!@rename($tmp, $file))
  {
    @unlink($tmp);
    throw new RuntimeException('Warmup-Einstellungen konnten nicht atomar gespeichert werden.');
  }

  return array('message'=>sprintf(
    'WebDAV-Cache-Warmup gespeichert: %s, %d Bilder pro Batch, Eingangsprüfung alle %d Stunden.',
    $enabled ? 'aktiv' : 'inaktiv',
    $batch_size,
    $periodic_hours
  ));
}
