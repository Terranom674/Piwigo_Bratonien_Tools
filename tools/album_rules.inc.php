<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_get_album_rules()
{
  return query2array('SELECT * FROM '.bratonien_tools_table('watermark_rules').' ORDER BY category_id');
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

  if (!in_array($mode, array('inherit','profile','disabled'), true))
  {
    throw new RuntimeException('Ungueltige Regel.');
  }

  $table = bratonien_tools_table('watermark_rules');
  pwg_query('DELETE FROM '.$table.' WHERE category_id='.$category);

  mass_inserts($table, array('category_id','mode','profile_id'), array(array(
    'category_id'=>$category,
    'mode'=>$mode,
    'profile_id'=>$profile,
  )));

  return array('message'=>'Albumregel gespeichert.');
}

function bratonien_tools_get_category_tree()
{
  $categories = array();
  $query = 'SELECT id,name,parent_id FROM '.CATEGORIES_TABLE.' ORDER BY uppercats';
  foreach (query2array($query) as $category)
  {
    $categories[] = $category;
  }
  return $categories;
}
