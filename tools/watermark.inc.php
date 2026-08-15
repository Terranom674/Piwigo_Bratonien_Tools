<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(BRATONIEN_TOOLS_PATH . 'include/watermark_base.inc.php');

function bratonien_tools_get_base_watermark_scale()
{
  $config = bratonien_tools_get_base_watermark_config();
  return max(1.0, min(1000.0, (float)$config['scale_percent']));
}

function bratonien_tools_save_base_watermark_scale($scale_percent)
{
  $config = bratonien_tools_get_base_watermark_config();
  $config['scale_percent'] = max(1.0, min(1000.0, (float)$scale_percent));
  bratonien_tools_save_base_watermark_config($config);
}

function bratonien_tools_get_watermark_data()
{
  $watermark = bratonien_tools_get_base_watermark_config();
  $files = array();
  $watermark_dir = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks';

  if (is_dir($watermark_dir))
  {
    $glob = glob($watermark_dir . '/*.png');
    if ($glob !== false)
    {
      foreach ($glob as $file)
      {
        $relative = substr($file, strlen(PHPWG_ROOT_PATH));
        $files[$relative] = basename($file);
      }
    }
  }

  ksort($files, SORT_NATURAL | SORT_FLAG_CASE);

  return array(
    'file' => (string)$watermark['file'],
    'xpos' => (int)$watermark['xpos'],
    'ypos' => (int)$watermark['ypos'],
    'xrepeat' => (int)$watermark['xrepeat'],
    'yrepeat' => (int)$watermark['yrepeat'],
    'opacity' => (int)$watermark['opacity'],
    'minw' => (int)$watermark['minw'],
    'minh' => (int)$watermark['minh'],
    'files' => $files,
    'preview_url' => !empty($watermark['file']) ? get_root_url() . $watermark['file'] : '',
    'scale_percent' => max(1.0, min(1000.0, (float)$watermark['scale_percent'])),
  );
}

function bratonien_tools_normalize_watermark_file($file)
{
  $file = ltrim(trim((string)$file), '/');
  if ($file === '')
  {
    throw new RuntimeException('Keine Wasserzeichendatei ausgewaehlt.');
  }

  $root = realpath(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks');
  $path = realpath(PHPWG_ROOT_PATH . $file);
  if (!$root || !$path || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path))
  {
    throw new RuntimeException('Die ausgewaehlte Wasserzeichendatei ist ungueltig.');
  }

  if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'png')
  {
    throw new RuntimeException('Es koennen nur PNG-Wasserzeichen geloescht werden.');
  }

  return array('relative'=>$file, 'path'=>$path);
}

function bratonien_tools_delete_watermark_file()
{
  if (!is_webmaster())
  {
    throw new RuntimeException('Nur ein Piwigo-Webmaster darf Wasserzeichendateien loeschen.');
  }

  $target = bratonien_tools_normalize_watermark_file($_POST['watermark_file'] ?? '');
  $relative = $target['relative'];

  if (!function_exists('bratonien_tools_table'))
  {
    require_once(BRATONIEN_TOOLS_PATH . 'include/database.class.php');
  }
  bratonien_tools_create_tables();

  $profiles = query2array(
    "SELECT id,name FROM ".bratonien_tools_table('watermark_profiles').
    " WHERE watermark_file='".pwg_db_real_escape_string($relative)."' ORDER BY name"
  );
  if (!empty($profiles))
  {
    $names = array();
    foreach ($profiles as $profile)
    {
      $names[] = $profile['name'];
    }
    throw new RuntimeException('Die Datei wird noch von folgenden Profilen verwendet: '.implode(', ', $names).'. Bitte dort zuerst eine andere Datei waehlen.');
  }

  $current = bratonien_tools_get_base_watermark_config();
  if (ltrim((string)$current['file'], '/') === $relative)
  {
    throw new RuntimeException('Die Datei ist aktuell als Basis-Wasserzeichen ausgewaehlt. Bitte zuerst eine andere Datei speichern.');
  }

  if (!@unlink($target['path']))
  {
    throw new RuntimeException('Die Wasserzeichendatei konnte nicht geloescht werden.');
  }

  require_once(BRATONIEN_TOOLS_PATH . 'tools/image_cache.inc.php');
  $cache_result = bratonien_tools_clear_image_cache();

  return array(
    'message' => 'Wasserzeichendatei geloescht. '.$cache_result['message'],
  );
}

function bratonien_tools_save_watermark()
{
  if (!is_webmaster())
  {
    throw new RuntimeException('Nur ein Piwigo-Webmaster darf das Wasserzeichen aendern.');
  }

  $current = bratonien_tools_get_base_watermark_config();
  $selected_file = isset($_POST['watermark_file']) ? trim((string) $_POST['watermark_file']) : (string)$current['file'];

  if (isset($_FILES['watermark_upload']) && !empty($_FILES['watermark_upload']['tmp_name']))
  {
    if (!is_uploaded_file($_FILES['watermark_upload']['tmp_name']))
    {
      throw new RuntimeException('Der Wasserzeichen-Upload ist ungueltig.');
    }

    $image_info = @getimagesize($_FILES['watermark_upload']['tmp_name']);
    if ($image_info === false || $image_info[2] !== IMAGETYPE_PNG)
    {
      throw new RuntimeException('Als Wasserzeichen sind nur PNG-Dateien erlaubt.');
    }

    $upload_dir = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks';
    if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true))
    {
      throw new RuntimeException('Das Wasserzeichen-Verzeichnis konnte nicht angelegt werden.');
    }

    $base = pathinfo($_FILES['watermark_upload']['name'], PATHINFO_FILENAME);
    $base = function_exists('str2url') ? str2url($base) : preg_replace('/[^A-Za-z0-9_-]+/', '-', $base);
    $base = trim((string) $base, '-_');
    if ($base === '')
    {
      $base = 'watermark';
    }

    $candidate = $base . '.png';
    $counter = 1;
    while (file_exists($upload_dir . '/' . $candidate))
    {
      $candidate = $base . '-' . $counter . '.png';
      $counter++;
    }

    $target = $upload_dir . '/' . $candidate;
    if (!@move_uploaded_file($_FILES['watermark_upload']['tmp_name'], $target))
    {
      throw new RuntimeException('Das hochgeladene Wasserzeichen konnte nicht gespeichert werden.');
    }

    @chmod($target, 0644);
    $selected_file = substr($target, strlen(PHPWG_ROOT_PATH));
  }

  if ($selected_file === '')
  {
    throw new RuntimeException('Bitte ein Wasserzeichen auswaehlen oder eine PNG-Datei hochladen.');
  }

  $absolute_selected = realpath(PHPWG_ROOT_PATH . $selected_file);
  $watermark_root = realpath(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks');
  if ($absolute_selected === false || $watermark_root === false || strpos($absolute_selected, $watermark_root . DIRECTORY_SEPARATOR) !== 0)
  {
    throw new RuntimeException('Das ausgewaehlte Wasserzeichen liegt nicht im erlaubten Wasserzeichen-Verzeichnis.');
  }

  $scale_percent = isset($_POST['watermark_scale_percent']) ? (float)$_POST['watermark_scale_percent'] : (float)$current['scale_percent'];
  if (!is_finite($scale_percent) || $scale_percent < 1 || $scale_percent > 1000)
  {
    throw new RuntimeException('Die Wasserzeichengroesse muss zwischen 1 und 1000 Prozent liegen.');
  }

  $config = array(
    'file' => $selected_file,
    'xpos' => bratonien_tools_watermark_int('watermark_xpos', 0, 100, 90),
    'ypos' => bratonien_tools_watermark_int('watermark_ypos', 0, 100, 90),
    'xrepeat' => bratonien_tools_watermark_int('watermark_xrepeat', 0, 100000, 0),
    'yrepeat' => bratonien_tools_watermark_int('watermark_yrepeat', 0, 100000, 0),
    'opacity' => bratonien_tools_watermark_int('watermark_opacity', 1, 100, 35),
    'minw' => bratonien_tools_watermark_int('watermark_minw', 0, 100000, 10),
    'minh' => bratonien_tools_watermark_int('watermark_minh', 0, 100000, 10),
    'scale_percent' => round($scale_percent, 2),
  );
  bratonien_tools_save_base_watermark_config($config);

  // If the Bratonien engine is active, native Piwigo watermarking must stay
  // fully neutralized. In particular the native watermark file must remain
  // empty because Piwigo derives use_watermark from it for custom derivatives.
  if (function_exists('bratonien_tools_watermark_engine_enabled') && bratonien_tools_watermark_engine_enabled())
  {
    bratonien_tools_disable_piwigo_watermarks();
  }

  $cache_message = '';
  if (!empty($_POST['watermark_clear_cache']))
  {
    require_once(BRATONIEN_TOOLS_PATH . 'tools/image_cache.inc.php');
    $cache_result = bratonien_tools_clear_image_cache();
    $cache_message = ' ' . $cache_result['message'];
  }

  if (function_exists('pwg_activity'))
  {
    pwg_activity('system', ACTIVITY_SYSTEM_PLUGIN, 'config', array('config_section' => 'bratonien_watermark'));
  }

  return array(
    'message' => 'Basis-Wasserzeichen gespeichert.' . $cache_message,
  );
}

function bratonien_tools_watermark_int($field, $min, $max, $default)
{
  $value = isset($_POST[$field]) ? filter_var($_POST[$field], FILTER_VALIDATE_INT) : $default;
  if ($value === false || $value < $min || $value > $max)
  {
    throw new RuntimeException(sprintf('%s muss zwischen %d und %d liegen.', $field, $min, $max));
  }
  return (int) $value;
}
