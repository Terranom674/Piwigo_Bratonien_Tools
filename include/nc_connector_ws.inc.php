<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Registers the connector-owned filesystem synchronization endpoint.
 *
 * Authentication and authorization are delegated to Piwigo's Web API.
 * The method is administrator-only and POST-only so an API key inherits the
 * permissions of its Piwigo user without storing that user's password.
 */
function bratonien_tools_register_ws_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'bratonien.nc.sync',
    'bratonien_tools_ws_nc_sync',
    array(
      'site_id' => array(
        'default' => 1,
        'type' => WS_TYPE_ID,
        'info' => 'Piwigo storage site to synchronize. Default: 1.',
      ),
    ),
    'Synchronizes a local Piwigo filesystem site for the Bratonien NC Connector.',
    null,
    array(
      'admin_only' => true,
      'post_only' => true,
    )
  );
}

/**
 * Runs the same local filesystem synchronization currently used by Piwigo's
 * admin site_update page, but inside an authenticated Web API request.
 */
function bratonien_tools_ws_nc_sync($params, &$service)
{
  global $conf, $template, $page, $user;

  if (empty($conf['enable_synchronization']))
  {
    return new PwgError(403, 'Piwigo filesystem synchronization is disabled.');
  }

  $site_id = isset($params['site_id']) ? (int)$params['site_id'] : 1;
  if ($site_id < 1)
  {
    return new PwgError(400, 'Invalid site_id.');
  }

  $query = '\nSELECT galleries_url\n  FROM '.SITES_TABLE.'\n  WHERE id = '.$site_id.'\n  LIMIT 1';
  $result = pwg_query($query);
  if (!pwg_db_num_rows($result))
  {
    return new PwgError(404, 'Piwigo site does not exist.');
  }

  list($site_url) = pwg_db_fetch_row($result);
  if (url_is_remote($site_url))
  {
    return new PwgError(400, 'Remote Piwigo sites are not supported by this synchronization method.');
  }

  $saved_get = $_GET;
  $saved_post = $_POST;

  $_GET['site'] = (string)$site_id;
  $_POST = array(
    'sync' => 'files',
    'display_info' => '1',
    'privacy_level' => '0',
    'sync_meta' => '1',
    'simulate' => '0',
    'subcats-included' => '1',
    'submit' => '1',
  );

  $counts = array();
  $errors = array();
  $infos = array();
  $general_failure = true;

  try
  {
    ob_start();
    include(PHPWG_ROOT_PATH.'admin/site_update.php');
    ob_end_clean();
  }
  catch (Throwable $e)
  {
    if (ob_get_level() > 0)
    {
      ob_end_clean();
    }
    $_GET = $saved_get;
    $_POST = $saved_post;
    return new PwgError(500, 'Piwigo synchronization failed: '.$e->getMessage());
  }

  $_GET = $saved_get;
  $_POST = $saved_post;

  if ($general_failure)
  {
    return new PwgError(500, 'Piwigo could not open the configured local site.');
  }

  return array(
    'site_id' => $site_id,
    'site_url' => $site_url,
    'counts' => $counts,
    'errors' => $errors,
    'info_count' => count($infos),
    'connected_with' => isset($_SESSION['connected_with']) ? (string)$_SESSION['connected_with'] : '',
    'username' => isset($user['username']) ? (string)$user['username'] : '',
    'status' => isset($user['status']) ? (string)$user['status'] : '',
  );
}
