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
require_once(BRATONIEN_TOOLS_PATH . 'include/watermark_engine.inc.php');

function bratonien_tools_get_tools()
{
  return array(
    'image_cache_clear' => array(
      'handler' => 'bratonien_tools_clear_image_cache',
    ),
    'image_cache_build' => array(
      'handler' => 'bratonien_tools_start_main_cache_build',
    ),
    'image_cache_cancel' => array(
      'handler' => 'bratonien_tools_cancel_main_cache_build',
    ),
    'watermark_save' => array(
      'handler' => 'bratonien_tools_save_watermark',
    ),
    'watermark_file_delete' => array(
      'handler' => 'bratonien_tools_delete_watermark_file',
    ),
    'watermark_engine' => array(
      'handler' => 'bratonien_tools_handle_watermark_engine',
    ),
    'watermark_profile_save' => array(
      'handler' => 'bratonien_tools_save_watermark_profile',
    ),
    'watermark_profile_delete' => array(
      'handler' => 'bratonien_tools_delete_watermark_profile',
    ),
    'watermark_profile_duplicate' => array(
      'handler' => 'bratonien_tools_duplicate_watermark_profile',
    ),
    'watermark_defaults' => array(
      'handler' => 'bratonien_tools_save_watermark_defaults',
    ),
    'watermark_rule' => array(
      'handler' => 'bratonien_tools_save_album_rule',
    ),
  );
}

function bratonien_tools_handle_watermark_engine()
{
  $enabled = !empty($_POST['engine_enabled']);
  bratonien_tools_set_watermark_engine($enabled);

  return array(
    'message' => $enabled
      ? 'Bratonien-Wasserzeichenverwaltung aktiviert. Piwigos bisherige Wasserzeichen-Nutzung wurde gesichert und unterdrueckt.'
      : 'Bratonien-Wasserzeichenverwaltung deaktiviert. Die zuvor gesicherte Piwigo-Wasserzeichen-Nutzung wurde wiederhergestellt.',
  );
}
