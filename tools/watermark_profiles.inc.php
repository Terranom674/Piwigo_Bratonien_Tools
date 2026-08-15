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
    mass_inserts(
      $table,
      array('name', 'watermark_file', 'xpos', 'ypos', 'opacity', 'min_width', 'min_height', 'created'),
      array(
        array('name'=>'Oeffentlich', 'watermark_file'=>'', 'xpos'=>90, 'ypos'=>90, 'opacity'=>35, 'min_width'=>10, 'min_height'=>10, 'created'=>date('Y-m-d H:i:s')),
        array('name'=>'Kein Wasserzeichen', 'watermark_file'=>'', 'xpos'=>90, 'ypos'=>90, 'opacity'=>0, 'min_width'=>0, 'min_height'=>0, 'created'=>date('Y-m-d H:i:s')),
      )
    );
  }
}

function bratonien_tools_get_watermark_profiles()
{
  bratonien_tools_create_default_watermark_profiles();
  return query2array('SELECT * FROM '.bratonien_tools_table('watermark_profiles').' ORDER BY name');
}

function bratonien_tools_save_watermark_profile()
{
  throw new RuntimeException('Profilverwaltung ist vorbereitet, die Bearbeitungsoberflaeche folgt im naechsten Schritt.');
}
