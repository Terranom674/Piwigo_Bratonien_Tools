<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_register_nc_productive_ws_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'bratonien.nc.syncProductive',
    'bratonien_tools_ws_nc_sync_productive',
    array(
      'site_id' => array(
        'default' => 1,
        'type' => WS_TYPE_ID,
        'info' => 'Piwigo storage site to synchronize. Default: 1.',
      ),
    ),
    'Runs the approved Piwigo filesystem synchronization for the Bratonien NC Connector.',
    null,
    array(
      'admin_only' => true,
      'post_only' => true,
    )
  );
}

function bratonien_tools_ws_nc_sync_productive($params, &$service)
{
  global $conf, $user, $template, $page;

  $piwigo_version = defined('PHPWG_VERSION') ? (string)PHPWG_VERSION : '';
  if ($piwigo_version !== '16.4.0')
  {
    return new PwgError(
      409,
      'Bratonien API synchronization is not approved for Piwigo '.$piwigo_version.'. Use the administrator fallback until this Piwigo version has been verified.'
    );
  }

  if (empty($conf['enable_synchronization']))
  {
    return new PwgError(403, 'Piwigo filesystem synchronization is disabled.');
  }

  $site_id = isset($params['site_id']) ? (int)$params['site_id'] : 1;
  if ($site_id < 1)
  {
    return new PwgError(400, 'Invalid site_id.');
  }

  $saved_get = $_GET;
  $saved_post = $_POST;
  $saved_request_method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
  $saved_default_status = isset($conf['newcat_default_status']) ? $conf['newcat_default_status'] : null;
  $output = '';
  $counts = array();

  try
  {
    if (!defined('IN_ADMIN'))
    {
      define('IN_ADMIN', true);
    }

    $conf['newcat_default_status'] = 'private';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = array(
      'page' => 'site_update',
      'site' => $site_id,
    );
    $_POST = array(
      'sync' => 'files',
      'display_info' => 1,
      'privacy_level' => 0,
      'sync_meta' => 1,
      'simulate' => 0,
      'subcats-included' => 1,
      'bratonien_connector' => 1,
      'submit' => 1,
    );

    ob_start();
    include(PHPWG_ROOT_PATH.'admin/site_update.php');
    $output = (string)ob_get_clean();
  }
  catch (Throwable $e)
  {
    if (ob_get_level() > 0)
    {
      ob_end_clean();
    }
    return new PwgError(500, 'Piwigo core synchronization failed: '.$e->getMessage());
  }
  finally
  {
    $_GET = $saved_get;
    $_POST = $saved_post;
    if ($saved_default_status === null)
    {
      unset($conf['newcat_default_status']);
    }
    else
    {
      $conf['newcat_default_status'] = $saved_default_status;
    }
    if ($saved_request_method === null)
    {
      unset($_SERVER['REQUEST_METHOD']);
    }
    else
    {
      $_SERVER['REQUEST_METHOD'] = $saved_request_method;
    }
  }

  return array(
    'mode' => 'productive',
    'approved_piwigo_version' => '16.4.0',
    'piwigo_version' => $piwigo_version,
    'site_id' => $site_id,
    'counts' => is_array($counts) ? $counts : array(),
    'database_writes' => true,
    'core_output_bytes' => strlen($output),
    'username' => isset($user['username']) ? (string)$user['username'] : '',
    'status' => isset($user['status']) ? (string)$user['status'] : '',
  );
}
