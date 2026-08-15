<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(BRATONIEN_TOOLS_PATH . 'tools/image_cache.inc.php');

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
  );

  // Weitere Werkzeuge werden hier registriert. Die Admin-Oberflaeche
  // rendert jeden Eintrag automatisch als eigene Werkzeug-Karte.
  return $tools;
}
