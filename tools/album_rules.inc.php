<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_get_album_rules()
{
  $rules = array();
  foreach (query2array('SELECT * FROM '.bratonien_tools_table('watermark_rules').' ORDER BY category_id') as $row)
  {
    $rules[(int)$row['category_id']] = $row;
  }
  return $rules;
}

function bratonien_tools_save_album_rule()
{
  $category = (int)($_POST['category_id'] ?? 0);
  $mode = (string)($_POST['rule_mode'] ?? 'inherit');
  $profile = !empty($_POST['rule_profile']) ? (int)$_POST['rule_profile'] : null;

  if ($category <= 0)
  {
    throw new RuntimeException('Ungueltiges Album.');
  }

  $exists = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.CATEGORIES_TABLE.' WHERE id='.$category));
  if ((int)$exists[0] !== 1)
  {
    throw new RuntimeException('Album nicht gefunden.');
  }

  if (!in_array($mode, array('inherit','profile','disabled'), true))
  {
    throw new RuntimeException('Ungueltige Regel.');
  }

  if ($mode === 'profile')
  {
    $selected = $profile ? bratonien_tools_get_watermark_profile($profile) : null;
    if (!$selected || empty($selected['active']))
    {
      throw new RuntimeException('Bitte ein gueltiges aktives Wasserzeichenprofil auswaehlen.');
    }
  }
  else
  {
    $profile = null;
  }

  $table = bratonien_tools_table('watermark_rules');
  pwg_query('DELETE FROM '.$table.' WHERE category_id='.$category);

  if ($mode !== 'inherit')
  {
    mass_inserts($table, array('category_id','mode','profile_id'), array(array(
      'category_id'=>$category,
      'mode'=>$mode,
      'profile_id'=>$profile,
    )));
  }

  return array('message'=>'Albumregel gespeichert.');
}

function bratonien_tools_get_category_tree()
{
  $result = array();
  $query = 'SELECT id,name,id_uppercat,status,uppercats,global_rank FROM '.CATEGORIES_TABLE.' ORDER BY global_rank';

  foreach (query2array($query) as $category)
  {
    $depth = 0;
    if (!empty($category['uppercats']))
    {
      $depth = max(0, count(explode(',', $category['uppercats'])) - 1);
    }

    $category['depth'] = $depth;
    $category['display_name'] = str_repeat('— ', $depth).$category['name'];
    $result[] = $category;
  }

  return $result;
}

function bratonien_tools_resolve_album_rule($category_id, array $categories, array $rules, array $defaults)
{
  $by_id = array();
  foreach ($categories as $category)
  {
    $by_id[(int)$category['id']] = $category;
  }

  $category_id = (int)$category_id;
  $root = $by_id[$category_id] ?? null;
  $is_private = $root && isset($root['status']) && $root['status'] === 'private';

  // Privat ist eine Vererbungsgrenze. Eine direkt auf diesem privaten Album
  // gesetzte Regel bleibt moeglich, aber Regeln oeffentlicher Eltern duerfen
  // nicht in ein privates Album hineinvererbt werden.
  if ($is_private)
  {
    if (isset($rules[$category_id]))
    {
      $rule = $rules[$category_id];
      if ($rule['mode'] === 'disabled')
      {
        return array('mode'=>'disabled','profile_id'=>null,'source'=>'album');
      }
      if ($rule['mode'] === 'profile')
      {
        return array('mode'=>'profile','profile_id'=>(int)$rule['profile_id'],'source'=>'album');
      }
    }

    $profile_id = $defaults['private_profile'] ?? null;
    if (empty($profile_id))
    {
      return array('mode'=>'disabled','profile_id'=>null,'source'=>'global');
    }
    return array('mode'=>'profile','profile_id'=>(int)$profile_id,'source'=>'global');
  }

  $current = $category_id;
  $visited = array();

  while ($current > 0 && isset($by_id[$current]) && !isset($visited[$current]))
  {
    $visited[$current] = true;

    if (isset($rules[$current]))
    {
      $rule = $rules[$current];
      if ($rule['mode'] === 'disabled')
      {
        return array('mode'=>'disabled','profile_id'=>null,'source'=>'album');
      }
      if ($rule['mode'] === 'profile')
      {
        return array('mode'=>'profile','profile_id'=>(int)$rule['profile_id'],'source'=>'album');
      }
    }

    $current = (int)($by_id[$current]['id_uppercat'] ?? 0);
  }

  $profile_id = $defaults['public_profile'] ?? null;
  if (empty($profile_id))
  {
    return array('mode'=>'disabled','profile_id'=>null,'source'=>'global');
  }

  return array('mode'=>'profile','profile_id'=>(int)$profile_id,'source'=>'global');
}
