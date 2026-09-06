<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_default_public_watermark_profile_id()
{
  if (!function_exists('bratonien_tools_create_default_watermark_profiles'))
  {
    return null;
  }

  bratonien_tools_create_default_watermark_profiles();
  $table = bratonien_tools_table('watermark_profiles');
  $row = pwg_db_fetch_assoc(pwg_query(
    "SELECT id FROM ".$table.
    " WHERE active=1 AND name='Oeffentlich' ORDER BY id ASC LIMIT 1"
  ));
  return $row ? (int)$row['id'] : null;
}

function bratonien_tools_get_watermark_defaults()
{
  $defaults = conf_get_param('bratonien_watermark_defaults', null);
  $defaults = $defaults ? json_decode($defaults, true) : array();
  $defaults = array_merge(array(
    'public_profile' => null,
    'private_profile' => null,
  ), is_array($defaults) ? $defaults : array());

  // Oeffentliche Alben sind standardmaessig geschuetzt. Nur eine explizite
  // Albumregel (anderes Profil oder deaktiviert) darf dieses Verhalten
  // ueberschreiben. Bestehende Installationen ohne gesetztes globales
  // Oeffentlich-Profil werden automatisch auf das mitgelieferte Profil
  // "Oeffentlich" migriert.
  if (empty($defaults['public_profile']))
  {
    $public_profile = bratonien_tools_default_public_watermark_profile_id();
    if ($public_profile)
    {
      $defaults['public_profile'] = $public_profile;
      conf_update_param('bratonien_watermark_defaults', json_encode($defaults));
    }
  }

  return $defaults;
}

function bratonien_tools_validate_default_profile($value)
{
  if ($value === '' || $value === null)
  {
    return null;
  }

  $id = (int)$value;
  $profile = $id > 0 ? bratonien_tools_get_watermark_profile($id) : null;
  if (!$profile || empty($profile['active']))
  {
    throw new RuntimeException('Ungueltiges oder inaktives Wasserzeichenprofil in den globalen Regeln.');
  }

  return $id;
}

function bratonien_tools_save_watermark_defaults()
{
  $public_profile = bratonien_tools_validate_default_profile($_POST['public_profile'] ?? null);
  if ($public_profile === null)
  {
    $public_profile = bratonien_tools_default_public_watermark_profile_id();
  }

  $config = array(
    'public_profile' => $public_profile,
    'private_profile' => bratonien_tools_validate_default_profile($_POST['private_profile'] ?? null),
  );

  conf_update_param('bratonien_watermark_defaults', json_encode($config));

  if (function_exists('bratonien_tools_presentation_refresh_enqueue_all'))
  {
    bratonien_tools_presentation_refresh_enqueue_all('global-watermark-rules-changed');
  }

  return array('message'=>'Globale Wasserzeichenregeln gespeichert. Oeffentliche Alben verwenden ohne abweichende Albumregel immer das Standard-Wasserzeichenprofil. Vorschauen werden im Hintergrund an die neuen Regeln angepasst.');
}
