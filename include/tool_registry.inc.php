<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(BRATONIEN_TOOLS_PATH . 'tools/image_cache.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/watermark.inc.php');

function bratonien_tools_get_tools()
{
  $tools = array(
    'image_cache_clear' => array(
      'title' => 'Bildcache leeren',
      'description' => 'Loescht alle von Piwigo erzeugten Bilddateien im Bildcache. Originalbilder bleiben unveraendert und benoetigte Ansichten werden automatisch neu erzeugt.',
      'button' => 'Bildcache leeren',
      'confirm' => 'Wirklich den gesamten Bildcache leeren? Originalbilder werden nicht geloescht.',
      'handler' => 'bratonien_tools_clear_image_cache',
      'danger' => true,
    ),
    'watermark' => array(
      'title' => 'Wasserzeichen verwalten',
      'description' => 'Verwaltet Wasserzeichenprofile und die Piwigo-Wasserzeichenkonfiguration. Profile und Albumregeln bilden die Grundlage fuer oeffentliche, private und spezielle Bereiche.',
      'button' => 'Wasserzeichen speichern',
      'confirm' => 'Wasserzeichenkonfiguration speichern?',
      'handler' => 'bratonien_tools_save_watermark',
      'danger' => false,
    ),
  );

  return $tools;
}
