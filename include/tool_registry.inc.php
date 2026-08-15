<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(BRATONIEN_TOOLS_PATH . 'tools/image_cache.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/watermark.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/watermark_profiles.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/watermark_settings.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/album_rules.inc.php');

function bratonien_tools_get_tools()
{
  return array(
    'image_cache_clear' => array('title'=>'Bildcache leeren','description'=>'Loescht erzeugte Bildderivate.','button'=>'Bildcache leeren','confirm'=>'Bildcache wirklich leeren?','handler'=>'bratonien_tools_clear_image_cache'),
    'watermark' => array('title'=>'Wasserzeichen verwalten','description'=>'Verwaltet Profile, globale Regeln und Albumregeln.','button'=>'Wasserzeichen speichern','confirm'=>'Wasserzeichen speichern?','handler'=>'bratonien_tools_save_watermark'),
    'watermark_rule' => array('title'=>'Albumregel','description'=>'Speichert eine Albumregel.','button'=>'Albumregel speichern','confirm'=>'Albumregel speichern?','handler'=>'bratonien_tools_save_album_rule'),
    'watermark_defaults' => array('title'=>'Globale Wasserzeichenregeln','description'=>'Definiert Standardprofile für öffentliche und private Alben.','button'=>'Standards speichern','confirm'=>'Standards speichern?','handler'=>'bratonien_tools_save_watermark_defaults'),
  );
}
