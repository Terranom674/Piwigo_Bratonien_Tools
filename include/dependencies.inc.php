<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

/**
 * Required third-party plugins for Bratonien Tools features.
 * gdThumb is intentionally NOT a dependency: the public selection UI must work
 * with Piwigo's native thumbnails and alternative thumbnail plugins/themes.
 */
function bratonien_tools_required_plugins()
{
  return array(
    'BatchDownloader' => array(
      'name' => 'Batch Downloader',
      'pem_extension_id' => 616,
      'required_for' => 'Fotoauswahl und Sammeldownloads',
    ),
  );
}

/**
 * Ensures required plugins are present and active.
 * Missing plugins are installed from Piwigo Extension Manager when automatic
 * extension installation is enabled. Failures are reported but do not destroy
 * unrelated Bratonien Tools functionality (for example watermarks).
 */
function bratonien_tools_ensure_dependencies(&$messages = array())
{
  global $conf;

  if (!defined('PHPWG_PLUGINS_PATH'))
  {
    $messages[] = 'Plugin-Abhängigkeiten konnten nicht geprüft werden: Piwigo-Pluginpfad fehlt.';
    return false;
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/plugins.class.php');
  $plugins = new plugins();
  $all_ok = true;

  foreach (bratonien_tools_required_plugins() as $plugin_id => $dependency)
  {
    // Plugin files missing: try to retrieve the compatible PEM revision.
    if (!isset($plugins->fs_plugins[$plugin_id]))
    {
      if (empty($conf['enable_extensions_install']))
      {
        $messages[] = $dependency['name'].' fehlt und die automatische Plugin-Installation ist in Piwigo deaktiviert.';
        $all_ok = false;
        continue;
      }

      if (!$plugins->get_server_plugins(true) || empty($plugins->server_plugins[$dependency['pem_extension_id']]))
      {
        $messages[] = $dependency['name'].' fehlt und konnte im Piwigo Extension Manager nicht gefunden werden.';
        $all_ok = false;
        continue;
      }

      $remote = $plugins->server_plugins[$dependency['pem_extension_id']];
      if (empty($remote['revision_id']))
      {
        $messages[] = $dependency['name'].' fehlt; der Extension Manager lieferte keine installierbare Revision.';
        $all_ok = false;
        continue;
      }

      $installed_id = null;
      $status = $plugins->extract_plugin_files('install', $remote['revision_id'], $dependency['pem_extension_id'], $installed_id);
      if ($status !== 'ok')
      {
        $messages[] = $dependency['name'].' konnte nicht automatisch installiert werden ('.$status.').';
        $all_ok = false;
        continue;
      }

      // Refresh the plugin inventory after extraction. The folder ID is the
      // authoritative runtime identifier, not the PEM extension number.
      $plugins = new plugins();
      if (!isset($plugins->fs_plugins[$plugin_id]))
      {
        $messages[] = $dependency['name'].' wurde geladen, aber nicht unter dem erwarteten Plugin-ID "'.$plugin_id.'" gefunden.';
        $all_ok = false;
        continue;
      }

      $messages[] = $dependency['name'].' wurde automatisch installiert.';
    }

    $state = isset($plugins->db_plugins_by_id[$plugin_id])
      ? $plugins->db_plugins_by_id[$plugin_id]['state']
      : 'uninstalled';

    if ($state !== 'active')
    {
      $errors = $plugins->perform_action('activate', $plugin_id);
      if (!empty($errors))
      {
        $messages[] = $dependency['name'].' konnte nicht aktiviert werden: '.implode(' ', (array)$errors);
        $all_ok = false;
        continue;
      }

      $messages[] = $dependency['name'].' wurde automatisch aktiviert.';
      $plugins = new plugins();
    }
  }

  return $all_ok;
}
