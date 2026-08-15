<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_get_watermark_rules()
{
  return query2array('SELECT * FROM '.bratonien_tools_table('watermark_rules').' ORDER BY category_id');
}

function bratonien_tools_save_watermark_rule()
{
  $category_id = (int)($_POST['category_id'] ?? 0);
  $mode = (string)($_POST['rule_mode'] ?? 'inherit');
  $profile_id = !empty($_POST['rule_profile']) ? (int)$_POST['rule_profile'] : 'NULL';

  if ($category_id <= 0)
  {
    throw new RuntimeException('Kein Album ausgewaehlt.');
  }

  pwg_query('DELETE FROM '.bratonien_tools_table('watermark_rules').' WHERE category_id='.$category_id);

  pwg_query("INSERT INTO ".bratonien_tools_table('watermark_rules')." (category_id, mode, profile_id) VALUES (".$category_id.", '".pwg_db_real_escape_string($mode)."', ".($profile_id === 'NULL' ? 'NULL' : $profile_id).")");

  return array('message'=>'Album-Wasserzeichenregel gespeichert.');
}
