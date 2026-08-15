<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_create_default_watermark_profiles()
{
  if (!function_exists('bratonien_tools_create_tables'))
  {
    require_once(BRATONIEN_TOOLS_PATH . 'include/database.class.php');
  }

  bratonien_tools_create_tables();

  $table = bratonien_tools_table('watermark_profiles');
  $count = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.$table));

  if ((int)$count[0] === 0)
  {
    mass_inserts($table, array('name','watermark_file','scale_percent','xpos','ypos','xrepeat','yrepeat','opacity','min_width','min_height','active','created'), array(
      array('name'=>'Oeffentlich','watermark_file'=>'','scale_percent'=>100,'xpos'=>90,'ypos'=>90,'xrepeat'=>0,'yrepeat'=>0,'opacity'=>35,'min_width'=>10,'min_height'=>10,'active'=>1,'created'=>date('Y-m-d H:i:s')),
      array('name'=>'Familie & Freunde','watermark_file'=>'','scale_percent'=>100,'xpos'=>90,'ypos'=>90,'xrepeat'=>0,'yrepeat'=>0,'opacity'=>25,'min_width'=>10,'min_height'=>10,'active'=>1,'created'=>date('Y-m-d H:i:s')),
    ));
  }
}

function bratonien_tools_get_watermark_profiles()
{
  bratonien_tools_create_default_watermark_profiles();
  return query2array('SELECT * FROM '.bratonien_tools_table('watermark_profiles').' ORDER BY name');
}

function bratonien_tools_get_watermark_profile($id)
{
  bratonien_tools_create_default_watermark_profiles();

  $id = (int)$id;
  if ($id <= 0)
  {
    return null;
  }

  $row = pwg_db_fetch_assoc(pwg_query('SELECT * FROM '.bratonien_tools_table('watermark_profiles').' WHERE id='.$id.' LIMIT 1'));
  return $row ?: null;
}

function bratonien_tools_validate_profile_file($file)
{
  $file = trim((string)$file);
  if ($file === '')
  {
    return '';
  }

  $data = bratonien_tools_get_watermark_data();
  if (!isset($data['files'][$file]))
  {
    throw new RuntimeException('Das ausgewaehlte Wasserzeichen existiert nicht.');
  }

  return $file;
}

function bratonien_tools_profile_scale_percent($value, $default=100.0)
{
  if ($value === null || $value === '')
  {
    return (float)$default;
  }

  if (!is_numeric($value))
  {
    throw new RuntimeException('Die Skalierung muss eine Zahl sein.');
  }

  $value = (float)$value;
  if ($value < 1 || $value > 1000)
  {
    throw new RuntimeException('Die Skalierung muss zwischen 1 und 1000 Prozent liegen.');
  }

  return round($value, 2);
}

function bratonien_tools_save_watermark_profile()
{
  $table = bratonien_tools_table('watermark_profiles');
  $id = (int)($_POST['profile_id'] ?? 0);

  $data = array(
    'name' => trim((string)($_POST['profile_name'] ?? '')),
    'watermark_file' => bratonien_tools_validate_profile_file($_POST['profile_file'] ?? ''),
    'scale_percent' => bratonien_tools_profile_scale_percent($_POST['profile_scale_percent'] ?? 100),
    'xpos' => max(0, min(100, (int)($_POST['profile_xpos'] ?? 90))),
    'ypos' => max(0, min(100, (int)($_POST['profile_ypos'] ?? 90))),
    'xrepeat' => max(0, min(20, (int)($_POST['profile_xrepeat'] ?? 0))),
    'yrepeat' => max(0, min(20, (int)($_POST['profile_yrepeat'] ?? 0))),
    'opacity' => max(1, min(100, (int)($_POST['profile_opacity'] ?? 35))),
    'min_width' => max(0, (int)($_POST['profile_min_width'] ?? 10)),
    'min_height' => max(0, (int)($_POST['profile_min_height'] ?? 10)),
    'active' => !empty($_POST['profile_active']) ? 1 : 0,
  );

  if ($data['name'] === '')
  {
    throw new RuntimeException('Profilname darf nicht leer sein.');
  }

  if ($id > 0)
  {
    if (!bratonien_tools_get_watermark_profile($id))
    {
      throw new RuntimeException('Wasserzeichenprofil nicht gefunden.');
    }

    $updates = array();
    foreach ($data as $key => $value)
    {
      $updates[] = $key."='".pwg_db_real_escape_string((string)$value)."'";
    }
    pwg_query('UPDATE '.$table.' SET '.implode(',', $updates).' WHERE id='.$id);
  }
  else
  {
    $data['created'] = date('Y-m-d H:i:s');
    mass_inserts($table, array_keys($data), array($data));
  }

  return array('message'=>'Wasserzeichenprofil gespeichert.');
}

function bratonien_tools_profile_is_referenced($id)
{
  $id = (int)$id;
  $rule_count = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.bratonien_tools_table('watermark_rules').' WHERE profile_id='.$id));
  if ((int)$rule_count[0] > 0)
  {
    return true;
  }

  $defaults = bratonien_tools_get_watermark_defaults();
  return ((int)($defaults['public_profile'] ?? 0) === $id || (int)($defaults['private_profile'] ?? 0) === $id);
}

function bratonien_tools_delete_watermark_profile()
{
  $id = (int)($_POST['profile_id'] ?? 0);
  if ($id <= 0 || !bratonien_tools_get_watermark_profile($id))
  {
    throw new RuntimeException('Ungueltiges Profil.');
  }

  if (bratonien_tools_profile_is_referenced($id))
  {
    throw new RuntimeException('Das Profil wird noch von globalen Einstellungen oder Albumregeln verwendet.');
  }

  pwg_query('DELETE FROM '.bratonien_tools_table('watermark_profiles').' WHERE id='.$id);
  return array('message'=>'Wasserzeichenprofil geloescht.');
}

function bratonien_tools_duplicate_watermark_profile()
{
  $id = (int)($_POST['profile_id'] ?? 0);
  $profile = bratonien_tools_get_watermark_profile($id);
  if (!$profile)
  {
    throw new RuntimeException('Wasserzeichenprofil nicht gefunden.');
  }

  unset($profile['id']);
  $profile['name'] .= ' (Kopie)';
  $profile['created'] = date('Y-m-d H:i:s');
  mass_inserts(bratonien_tools_table('watermark_profiles'), array_keys($profile), array($profile));

  return array('message'=>'Wasserzeichenprofil dupliziert.');
}
