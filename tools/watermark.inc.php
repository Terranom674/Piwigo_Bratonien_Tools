<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_get_base_watermark_scale()
{
  if (!function_exists('conf_get_param'))
  {
    return 100.0;
  }

  $value = (float)conf_get_param('bratonien_watermark_base_scale_percent', 100);
  return max(1.0, min(1000.0, $value > 0 ? $value : 100.0));
}

function bratonien_tools_save_base_watermark_scale($scale_percent)
{
  if (!function_exists('conf_update_param'))
  {
    throw new RuntimeException('Piwigo-Konfiguration ist nicht verfuegbar.');
  }

  $scale_percent = max(1.0, min(1000.0, (float)$scale_percent));
  conf_update_param('bratonien_watermark_base_scale_percent', (string)$scale_percent);
}

function bratonien_tools_get_watermark_data()
{
  if (!class_exists('ImageStdParams'))
  {
    require_once(PHPWG_ROOT_PATH . 'include/derivative_std_params.inc.php');
  }

  $watermark = ImageStdParams::get_watermark();
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
    'file' => $watermark->file,
    'xpos' => (int) $watermark->xpos,
    'ypos' => (int) $watermark->ypos,
    'xrepeat' => (int) $watermark->xrepeat,
    'yrepeat' => (int) $watermark->yrepeat,
    'opacity' => (int) $watermark->opacity,
    'minw' => isset($watermark->min_size[0]) ? (int) $watermark->min_size[0] : 0,
    'minh' => isset($watermark->min_size[1]) ? (int) $watermark->min_size[1] : 0,
    'files' => $files,
    'preview_url' => !empty($watermark->file) ? get_root_url() . $watermark->file : '',
    'scale_percent' => bratonien_tools_get_base_watermark_scale(),
  );
}

function bratonien_tools_save_watermark()
{
  if (!is_webmaster())
  {
    throw new RuntimeException('Nur ein Piwigo-Webmaster darf das Wasserzeichen aendern.');
  }

  if (!class_exists('ImageStdParams'))
  {
    require_once(PHPWG_ROOT_PATH . 'include/derivative_std_params.inc.php');
  }
  if (!function_exists('clear_derivative_cache'))
  {
    require_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');
  }

  $current = ImageStdParams::get_watermark();
  $selected_file = isset($_POST['watermark_file']) ? trim((string) $_POST['watermark_file']) : $current->file;

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

  $xpos = bratonien_tools_watermark_int('watermark_xpos', 0, 100, 90);
  $ypos = bratonien_tools_watermark_int('watermark_ypos', 0, 100, 90);
  $opacity = bratonien_tools_watermark_int('watermark_opacity', 1, 100, 35);
  $minw = bratonien_tools_watermark_int('watermark_minw', 0, 100000, 10);
  $minh = bratonien_tools_watermark_int('watermark_minh', 0, 100000, 10);
  $xrepeat = bratonien_tools_watermark_int('watermark_xrepeat', 0, 100000, 0);
  $yrepeat = bratonien_tools_watermark_int('watermark_yrepeat', 0, 100000, 0);

  $scale_percent = isset($_POST['watermark_scale_percent']) ? (float)$_POST['watermark_scale_percent'] : bratonien_tools_get_base_watermark_scale();
  if (!is_finite($scale_percent) || $scale_percent < 1 || $scale_percent > 1000)
  {
    throw new RuntimeException('Die Wasserzeichengroesse muss zwischen 1 und 1000 Prozent liegen.');
  }
  bratonien_tools_save_base_watermark_scale($scale_percent);

  $watermark = new WatermarkParams();
  $watermark->file = $selected_file;
  $watermark->xpos = $xpos;
  $watermark->ypos = $ypos;
  $watermark->xrepeat = $xrepeat;
  $watermark->yrepeat = $yrepeat;
  $watermark->opacity = $opacity;
  $watermark->min_size = array($minw, $minh);

  ImageStdParams::set_watermark($watermark);

  foreach (ImageStdParams::get_defined_type_map() as $type => $params)
  {
    ImageStdParams::apply_global($params);
    if ($params->use_watermark)
    {
      $params->last_mod_time = time();
    }
  }
  ImageStdParams::save();

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
    'message' => 'Wasserzeichen gespeichert.' . $cache_message,
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
