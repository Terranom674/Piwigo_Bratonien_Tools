<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

require_once(BRATONIEN_TOOLS_PATH . 'tools/image_cache.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/cache_clear_atomic.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/watermark.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/watermark_profiles.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/watermark_settings.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/album_rules.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'tools/asset_manager.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/watermark_engine.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/presentation_refresh.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/public_selection.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/self_update.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/album_shares.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/album_lock.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/customer_qr_upload.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/friendship_code_upload.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_piwigo_api.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_wizard.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_wizard_user_scope.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_connection_scope.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_delete_safe.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_webdav.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_wizard_webdav_flow.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/webdav_warmup_settings.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/webdav_scan_diagnostic.inc.php');

function bratonien_tools_get_tools()
{
  return array(
    'image_cache_clear' => array('handler' => 'bratonien_tools_clear_image_cache_atomic'),
    'image_cache_build' => array('handler' => 'bratonien_tools_start_combined_image_cache_build'),
    'image_cache_cancel' => array('handler' => 'bratonien_tools_cancel_combined_image_cache_build'),
    'image_cache_worker_settings' => array('handler' => 'bratonien_tools_save_cache_worker_settings'),
    'image_cache_webdav_warmup_settings' => array('handler' => 'bratonien_tools_save_webdav_warmup_settings'),
    'image_cache_webdav_warmup_manual' => array('handler' => 'bratonien_tools_start_webdav_warmup_manual'),
    'image_cache_webdav_warmup_audit' => array('handler' => 'bratonien_tools_run_webdav_warmup_audit'),
    'image_cache_webdav_scan_diagnostic' => array('handler' => 'bratonien_tools_run_webdav_scan_diagnostic'),
    'watermark_save' => array('handler' => 'bratonien_tools_save_watermark'),
    'watermark_file_delete' => array('handler' => 'bratonien_tools_delete_watermark_file'),
    'watermark_engine' => array('handler' => 'bratonien_tools_handle_watermark_engine'),
    'watermark_profile_save' => array('handler' => 'bratonien_tools_save_watermark_profile'),
    'watermark_profile_delete' => array('handler' => 'bratonien_tools_delete_watermark_profile'),
    'watermark_profile_duplicate' => array('handler' => 'bratonien_tools_duplicate_watermark_profile'),
    'watermark_defaults' => array('handler' => 'bratonien_tools_save_watermark_defaults'),
    'watermark_rule' => array('handler' => 'bratonien_tools_save_album_rule'),
    'public_selection_settings' => array('handler' => 'bratonien_tools_save_public_selection_settings'),
    'customer_qr_settings' => array('handler' => 'bratonien_tools_customer_qr_save_settings'),
    'customer_qr_delete' => array('handler' => 'bratonien_tools_customer_qr_delete_upload'),
    'friendship_code_delete' => array('handler' => 'bratonien_tools_friendship_code_delete_upload'),
    'asset_upload' => array('handler' => 'bratonien_tools_upload_asset'),
    'asset_delete' => array('handler' => 'bratonien_tools_delete_asset'),
    'asset_upload_limits' => array('handler' => 'bratonien_tools_save_upload_limits'),
    'album_lock_toggle' => array('handler' => 'bratonien_tools_toggle_album_lock'),
    'album_share_create' => array('handler' => 'bratonien_tools_create_album_share'),
    'album_share_regenerate_link' => array('handler' => 'bratonien_tools_regenerate_album_share_link'),
    'album_share_revoke' => array('handler' => 'bratonien_tools_revoke_album_share'),
    'nc_connector_create_webdav_parallel' => array('handler' => 'bratonien_tools_nc_connector_create_webdav_placeholder_from_wizard'),
    'nc_connector_delete' => array('handler' => 'bratonien_tools_nc_connector_delete_safe'),
    'nc_connector_update_name' => array('handler' => 'bratonien_tools_nc_connector_update_name'),
    'nc_connector_run_now' => array('handler' => 'bratonien_tools_nc_connector_run_now'),
    'nc_connector_wizard_scan' => array('handler' => 'bratonien_tools_nc_wizard_scan_webdav_first'),
    'nc_connector_wizard_directory_browse' => array('handler' => 'bratonien_tools_nc_wizard_directory_browse'),
    'nc_connector_wizard_directory_add' => array('handler' => 'bratonien_tools_nc_wizard_directory_add'),
    'nc_connector_wizard_directory_remove' => array('handler' => 'bratonien_tools_nc_wizard_directory_remove'),
    'nc_connector_wizard_save_mounts' => array('handler' => 'bratonien_tools_nc_wizard_save_sources_dispatch'),
    'nc_connector_wizard_select_user' => array('handler' => 'bratonien_tools_nc_wizard_select_current_user'),
    'nc_connector_wizard_api_test' => array('handler' => 'bratonien_tools_nc_wizard_api_test'),
    'nc_connector_wizard_api_skip' => array('handler' => 'bratonien_tools_nc_wizard_api_skip'),
    'nc_connector_wizard_finish' => array('handler' => 'bratonien_tools_nc_wizard_finish_dispatch'),
    'nc_connector_wizard_back' => array('handler' => 'bratonien_tools_nc_wizard_back'),
    'nc_connector_wizard_reset' => array('handler' => 'bratonien_tools_nc_wizard_reset'),
    'nc_connector_fallback_save' => array('handler' => 'bratonien_tools_nc_connector_fallback_save_scoped'),
    'nc_connector_fallback_delete' => array('handler' => 'bratonien_tools_nc_connector_fallback_delete_scoped'),
    'self_update_check' => array('handler' => 'bratonien_tools_self_update_check'),
    'self_update_run' => array('handler' => 'bratonien_tools_self_update_run'),
  );
}

function bratonien_tools_handle_watermark_engine()
{
  $enabled = !empty($_POST['engine_enabled']);
  bratonien_tools_set_watermark_engine($enabled);

  if (function_exists('bratonien_tools_presentation_refresh_enqueue_all'))
  {
    bratonien_tools_presentation_refresh_enqueue_all('watermark-engine-changed');
  }

  return array(
    'message' => $enabled
      ? 'Bratonien-Wasserzeichenverwaltung aktiviert. Piwigos bisherige Wasserzeichen-Nutzung wurde gesichert und die Vorschauen werden aktualisiert.'
      : 'Bratonien-Wasserzeichenverwaltung deaktiviert. Die zuvor gesicherte Piwigo-Wasserzeichen-Nutzung wurde wiederhergestellt und die Vorschauen werden aktualisiert.',
  );
}
