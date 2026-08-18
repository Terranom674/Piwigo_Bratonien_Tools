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
require_once(BRATONIEN_TOOLS_PATH . 'tools/asset_manager.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/watermark_engine.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/public_selection.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/self_update.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/album_shares.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/album_lock.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_manage.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_takeover.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_auth.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_create_api.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_piwigo_api.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_wizard.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_wizard_db_bridge.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_wizard_user_scope.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_wizard_flow.inc.php');
require_once(BRATONIEN_TOOLS_PATH . 'include/nc_connector_connection_scope.inc.php');

function bratonien_tools_get_tools()
{
  return array(
    'image_cache_clear' => array('handler' => 'bratonien_tools_clear_image_cache'),
    'image_cache_build' => array('handler' => 'bratonien_tools_start_main_cache_build'),
    'image_cache_cancel' => array('handler' => 'bratonien_tools_cancel_main_cache_build'),
    'image_cache_worker_settings' => array('handler' => 'bratonien_tools_save_cache_worker_settings'),
    'watermark_save' => array('handler' => 'bratonien_tools_save_watermark'),
    'watermark_file_delete' => array('handler' => 'bratonien_tools_delete_watermark_file'),
    'watermark_engine' => array('handler' => 'bratonien_tools_handle_watermark_engine'),
    'watermark_profile_save' => array('handler' => 'bratonien_tools_save_watermark_profile'),
    'watermark_profile_delete' => array('handler' => 'bratonien_tools_delete_watermark_profile'),
    'watermark_profile_duplicate' => array('handler' => 'bratonien_tools_duplicate_watermark_profile'),
    'watermark_defaults' => array('handler' => 'bratonien_tools_save_watermark_defaults'),
    'watermark_rule' => array('handler' => 'bratonien_tools_save_album_rule'),
    'public_selection_settings' => array('handler' => 'bratonien_tools_save_public_selection_settings'),
    'asset_upload' => array('handler' => 'bratonien_tools_upload_asset'),
    'asset_delete' => array('handler' => 'bratonien_tools_delete_asset'),
    'asset_upload_limits' => array('handler' => 'bratonien_tools_save_upload_limits'),
    'album_lock_toggle' => array('handler' => 'bratonien_tools_toggle_album_lock'),
    'album_share_create' => array('handler' => 'bratonien_tools_create_album_share'),
    'album_share_regenerate_link' => array('handler' => 'bratonien_tools_regenerate_album_share_link'),
    'album_share_revoke' => array('handler' => 'bratonien_tools_revoke_album_share'),
    'nc_connector_create_local' => array('handler' => 'bratonien_tools_nc_connector_create_local_api_first'),
    'nc_connector_delete' => array('handler' => 'bratonien_tools_nc_connector_delete_any'),
    'nc_connector_update_name' => array('handler' => 'bratonien_tools_nc_connector_update_name'),
    'nc_connector_update_technical' => array('handler' => 'bratonien_tools_nc_connector_update_technical'),
    'nc_connector_wizard_scan' => array('handler' => 'bratonien_tools_nc_wizard_scan_user_scoped'),
    'nc_connector_wizard_save_technical' => array('handler' => 'bratonien_tools_nc_wizard_save_technical_flow'),
    'nc_connector_wizard_directory_browse' => array('handler' => 'bratonien_tools_nc_wizard_directory_browse'),
    'nc_connector_wizard_directory_add' => array('handler' => 'bratonien_tools_nc_wizard_directory_add'),
    'nc_connector_wizard_directory_remove' => array('handler' => 'bratonien_tools_nc_wizard_directory_remove'),
    'nc_connector_wizard_save_mounts' => array('handler' => 'bratonien_tools_nc_wizard_save_mounts_server_side'),
    'nc_connector_wizard_select_user' => array('handler' => 'bratonien_tools_nc_wizard_select_current_user'),
    'nc_connector_wizard_api_test' => array('handler' => 'bratonien_tools_nc_wizard_api_test'),
    'nc_connector_wizard_api_skip' => array('handler' => 'bratonien_tools_nc_wizard_api_skip'),
    'nc_connector_wizard_finish' => array('handler' => 'bratonien_tools_nc_wizard_finish_connection_scoped'),
    'nc_connector_wizard_back' => array('handler' => 'bratonien_tools_nc_wizard_back'),
    'nc_connector_wizard_reset' => array('handler' => 'bratonien_tools_nc_wizard_reset'),
    'nc_connector_import_legacy' => array('handler' => 'bratonien_tools_nc_connector_import_legacy'),
    'nc_connector_verify' => array('handler' => 'bratonien_tools_nc_connector_verify_connection_scoped'),
    'nc_connector_prepare_takeover' => array('handler' => 'bratonien_tools_nc_connector_prepare_takeover'),
    'nc_connector_cancel_takeover' => array('handler' => 'bratonien_tools_nc_connector_cancel_takeover'),
    'nc_connector_piwigo_api_test' => array('handler' => 'bratonien_tools_nc_connector_piwigo_api_test'),
    'nc_connector_piwigo_api_delete' => array('handler' => 'bratonien_tools_nc_connector_api_delete'),
    'nc_connector_fallback_save' => array('handler' => 'bratonien_tools_nc_connector_fallback_save'),
    'nc_connector_fallback_delete' => array('handler' => 'bratonien_tools_nc_connector_fallback_delete'),
    'nc_connector_fallback_once' => array('handler' => 'bratonien_tools_nc_connector_fallback_once'),
    'self_update_check' => array('handler' => 'bratonien_tools_self_update_check'),
    'self_update_run' => array('handler' => 'bratonien_tools_self_update_run'),
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
