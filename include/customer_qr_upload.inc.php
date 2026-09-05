<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_customer_qr_table()
{
  return $GLOBALS['prefixeTable'].'bratonien_tools_customer_qr_uploads';
}

function bratonien_tools_customer_qr_year_limits()
{
  return array(
    2023 => 100,
    2024 => 50,
    2025 => 30,
    2026 => 30,
  );
}

function bratonien_tools_customer_qr_settings()
{
  $default = array(
    'enabled' => false,
  );

  if (!function_exists('conf_get_param'))
  {
    return $default;
  }

  $raw = conf_get_param('bratonien_customer_qr_upload', null);
  if ($raw === null || $raw === '')
  {
    return $default;
  }

  $decoded = json_decode($raw, true);
  return is_array($decoded) ? array_merge($default, $decoded) : $default;
}

function bratonien_tools_customer_qr_save_settings()
{
  if (!function_exists('conf_update_param'))
  {
    throw new RuntimeException('Piwigo-Konfiguration ist nicht verfügbar.');
  }

  $settings = array(
    'enabled' => !empty($_POST['customer_qr_enabled']),
  );
  conf_update_param('bratonien_customer_qr_upload', json_encode($settings));

  return array(
    'message' => $settings['enabled']
      ? 'Kunden-QR-Upload aktiviert.'
      : 'Kunden-QR-Upload deaktiviert.',
  );
}

function bratonien_tools_customer_qr_storage_root()
{
  return PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'bratonien-customer-qr';
}

function bratonien_tools_customer_qr_ensure_storage()
{
  $table = bratonien_tools_customer_qr_table();
  pwg_query("CREATE TABLE IF NOT EXISTS `$table` (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    upload_year smallint(5) unsigned NOT NULL,
    code_number varchar(32) NOT NULL,
    original_name varchar(255) NOT NULL,
    stored_name varchar(255) NOT NULL,
    mime_type varchar(100) NOT NULL,
    file_size bigint(20) unsigned NOT NULL DEFAULT 0,
    created datetime NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY year_code (upload_year, code_number),
    KEY upload_year (upload_year)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $root = bratonien_tools_customer_qr_storage_root();
  if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root))
  {
    throw new RuntimeException('QR-Upload-Verzeichnis konnte nicht angelegt werden.');
  }

  return $root;
}

function bratonien_tools_customer_qr_year($value)
{
  $year = (int)$value;
  $limits = bratonien_tools_customer_qr_year_limits();
  if (!isset($limits[$year]))
  {
    throw new InvalidArgumentException('Erlaubt sind nur die Jahre 2023, 2024, 2025 und 2026.');
  }
  return $year;
}

function bratonien_tools_customer_qr_default_year()
{
  $limits = bratonien_tools_customer_qr_year_limits();
  $current = (int)date('Y');
  if (isset($limits[$current]))
  {
    return $current;
  }

  $years = array_keys($limits);
  sort($years, SORT_NUMERIC);
  return (int)end($years);
}

function bratonien_tools_customer_qr_number($value)
{
  $value = trim((string)$value);
  if ($value === '' || !preg_match('/^[0-9]{1,32}$/', $value))
  {
    throw new InvalidArgumentException('Die QR-Code-Nummer darf nur aus Ziffern bestehen.');
  }

  $value = ltrim($value, '0');
  if ($value === '' || $value === '0')
  {
    throw new InvalidArgumentException('Die QR-Code-Nummer muss mindestens 1 sein.');
  }

  return $value;
}

function bratonien_tools_customer_qr_number_for_year($year, $value)
{
  $year = bratonien_tools_customer_qr_year($year);
  $number = bratonien_tools_customer_qr_number($value);
  $limits = bratonien_tools_customer_qr_year_limits();
  $limit = (int)$limits[$year];

  if (strlen($number) > strlen((string)$limit) || (int)$number > $limit)
  {
    throw new InvalidArgumentException('Für '.$year.' sind nur QR-Code-Nummern 1 bis '.$limit.' vorgesehen.');
  }

  return $number;
}

function bratonien_tools_customer_qr_exists($year, $number)
{
  bratonien_tools_customer_qr_ensure_storage();
  $year = bratonien_tools_customer_qr_year($year);
  $number = bratonien_tools_customer_qr_number_for_year($year, $number);
  $table = bratonien_tools_customer_qr_table();

  $query = 'SELECT id FROM `'.$table.'` WHERE upload_year='.(int)$year
    ." AND code_number='".pwg_db_real_escape_string($number)."' LIMIT 1";
  return pwg_db_num_rows(pwg_query($query)) > 0;
}

function bratonien_tools_customer_qr_files_array(array $files)
{
  if (!isset($files['name']))
  {
    return array();
  }

  if (!is_array($files['name']))
  {
    return array($files);
  }

  $normalized = array();
  $count = count($files['name']);
  for ($i = 0; $i < $count; $i++)
  {
    $normalized[] = array(
      'name' => isset($files['name'][$i]) ? $files['name'][$i] : '',
      'type' => isset($files['type'][$i]) ? $files['type'][$i] : '',
      'tmp_name' => isset($files['tmp_name'][$i]) ? $files['tmp_name'][$i] : '',
      'error' => isset($files['error'][$i]) ? $files['error'][$i] : UPLOAD_ERR_NO_FILE,
      'size' => isset($files['size'][$i]) ? $files['size'][$i] : 0,
    );
  }
  return $normalized;
}

function bratonien_tools_customer_qr_validate_image(array $file)
{
  if ((int)$file['error'] !== UPLOAD_ERR_OK)
  {
    throw new RuntimeException('Datei-Upload fehlgeschlagen (Code '.(int)$file['error'].').');
  }
  if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']))
  {
    throw new RuntimeException('Die hochgeladene Datei konnte nicht verifiziert werden.');
  }

  $image = @getimagesize($file['tmp_name']);
  $mime = is_array($image) && !empty($image['mime']) ? strtolower((string)$image['mime']) : '';
  $extensions = array(
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
  );

  if (!isset($extensions[$mime]))
  {
    throw new RuntimeException('Erlaubt sind PNG, JPG, WEBP und GIF.');
  }

  return array(
    'mime' => $mime,
    'extension' => $extensions[$mime],
  );
}

function bratonien_tools_customer_qr_unique_name($number, $extension)
{
  try
  {
    $suffix = bin2hex(random_bytes(6));
  }
  catch (Throwable $e)
  {
    $suffix = str_replace('.', '', uniqid('', true));
  }

  return 'qr-'.$number.'-'.$suffix.'.'.$extension;
}

function bratonien_tools_customer_qr_process_uploads($year, array $files, array $numbers)
{
  $year = bratonien_tools_customer_qr_year($year);
  $root = bratonien_tools_customer_qr_ensure_storage();
  $year_dir = $root.DIRECTORY_SEPARATOR.$year;
  if (!is_dir($year_dir) && !@mkdir($year_dir, 0770, true) && !is_dir($year_dir))
  {
    throw new RuntimeException('Jahresverzeichnis für den QR-Upload konnte nicht angelegt werden.');
  }

  $lock_dir = $root.DIRECTORY_SEPARATOR.'.locks';
  if (!is_dir($lock_dir) && !@mkdir($lock_dir, 0770, true) && !is_dir($lock_dir))
  {
    throw new RuntimeException('QR-Lockverzeichnis konnte nicht angelegt werden.');
  }

  $items = bratonien_tools_customer_qr_files_array($files);
  $results = array();
  $seen = array();

  foreach ($items as $index => $file)
  {
    $display_name = trim((string)($file['name'] ?? ''));
    $number = '';
    if ($display_name === '')
    {
      $display_name = 'Datei '.($index + 1);
    }

    try
    {
      $number = bratonien_tools_customer_qr_number_for_year($year, isset($numbers[$index]) ? $numbers[$index] : '');

      if (isset($seen[$number]))
      {
        $results[] = array(
          'status' => 'duplicate',
          'file' => $display_name,
          'year' => $year,
          'number' => $number,
          'message' => 'Nummer ist innerhalb dieses Batches doppelt.',
        );
        continue;
      }
      $seen[$number] = true;

      $lock_path = $lock_dir.DIRECTORY_SEPARATOR.$year.'-'.$number.'.lock';
      $lock = @fopen($lock_path, 'c+');
      if ($lock === false || !@flock($lock, LOCK_EX))
      {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Die Nummer konnte nicht sicher reserviert werden.');
      }

      try
      {
        if (bratonien_tools_customer_qr_exists($year, $number))
        {
          $results[] = array(
            'status' => 'duplicate',
            'file' => $display_name,
            'year' => $year,
            'number' => $number,
            'message' => 'Diese QR-Code-Nummer ist für das gewählte Jahr bereits vorhanden.',
          );
          continue;
        }

        $image = bratonien_tools_customer_qr_validate_image($file);
        $stored_name = bratonien_tools_customer_qr_unique_name($number, $image['extension']);
        $target = $year_dir.DIRECTORY_SEPARATOR.$stored_name;

        if (!@move_uploaded_file($file['tmp_name'], $target))
        {
          throw new RuntimeException('Die Datei konnte nicht dauerhaft gespeichert werden.');
        }
        @chmod($target, 0660);

        $table = bratonien_tools_customer_qr_table();
        $query = "INSERT INTO `$table` (upload_year, code_number, original_name, stored_name, mime_type, file_size, created) VALUES ("
          .(int)$year.", '".pwg_db_real_escape_string($number)."', '"
          .pwg_db_real_escape_string($display_name)."', '"
          .pwg_db_real_escape_string($stored_name)."', '"
          .pwg_db_real_escape_string($image['mime'])."', "
          .max(0, (int)($file['size'] ?? 0)).", NOW())";

        if (pwg_query($query) === false)
        {
          @unlink($target);
          throw new RuntimeException('Der Upload konnte nicht in der QR-Code-Liste gespeichert werden.');
        }

        $results[] = array(
          'status' => 'ok',
          'file' => $display_name,
          'year' => $year,
          'number' => $number,
          'message' => 'QR-Code erfolgreich gespeichert.',
        );
      }
      finally
      {
        @flock($lock, LOCK_UN);
        fclose($lock);
      }
    }
    catch (Throwable $e)
    {
      $results[] = array(
        'status' => 'error',
        'file' => $display_name,
        'year' => $year,
        'number' => $number,
        'message' => $e->getMessage(),
      );
    }
  }

  return $results;
}

function bratonien_tools_customer_qr_admin_data()
{
  bratonien_tools_customer_qr_ensure_storage();
  $settings = bratonien_tools_customer_qr_settings();
  $table = bratonien_tools_customer_qr_table();
  $limits = bratonien_tools_customer_qr_year_limits();
  $counts = array_fill_keys(array_keys($limits), 0);
  $total = 0;
  $current_year = bratonien_tools_customer_qr_default_year();

  $result = pwg_query('SELECT upload_year, COUNT(*) AS cnt FROM `'.$table.'` GROUP BY upload_year');
  while ($row = pwg_db_fetch_assoc($result))
  {
    $year = (int)$row['upload_year'];
    $count = (int)$row['cnt'];
    $total += $count;
    if (isset($counts[$year]))
    {
      $counts[$year] = $count;
    }
  }

  $years = array();
  foreach ($limits as $year => $capacity)
  {
    $used = (int)$counts[$year];
    $years[] = array(
      'year' => (int)$year,
      'capacity' => (int)$capacity,
      'used' => $used,
      'remaining' => max(0, (int)$capacity - $used),
    );
  }

  $year_keys = array_keys($limits);
  sort($year_keys, SORT_NUMERIC);

  return array(
    'enabled' => !empty($settings['enabled']),
    'url' => get_absolute_root_url(true).'plugins/'.BRATONIEN_TOOLS_ID.'/customer-qr-upload.php',
    'year_min' => (int)reset($year_keys),
    'year_max' => (int)end($year_keys),
    'default_year' => $current_year,
    'years' => $years,
    'total' => $total,
    'current_year_total' => (int)$counts[$current_year],
    'current_year_capacity' => (int)$limits[$current_year],
    'max_files' => max(1, (int)ini_get('max_file_uploads')),
  );
}
