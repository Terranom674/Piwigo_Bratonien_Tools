<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_table($name)
{
  return $GLOBALS['prefixeTable'] . 'bratonien_tools_' . $name;
}

function bratonien_tools_column_exists($table, $column)
{
  $result = pwg_query("SHOW COLUMNS FROM `".$table."` LIKE '".pwg_db_real_escape_string($column)."'");
  return pwg_db_num_rows($result) > 0;
}

function bratonien_tools_create_tables()
{
  $profiles = bratonien_tools_table('watermark_profiles');
  $rules = bratonien_tools_table('watermark_rules');

  pwg_query("CREATE TABLE IF NOT EXISTS `$profiles` (
    id int(11) NOT NULL AUTO_INCREMENT,
    name varchar(255) NOT NULL,
    watermark_file varchar(255) DEFAULT NULL,
    scale_percent decimal(8,2) NOT NULL DEFAULT 100.00,
    xpos int(11) NOT NULL DEFAULT 90,
    ypos int(11) NOT NULL DEFAULT 90,
    xrepeat int(11) NOT NULL DEFAULT 0,
    yrepeat int(11) NOT NULL DEFAULT 0,
    opacity int(11) NOT NULL DEFAULT 35,
    min_width int(11) NOT NULL DEFAULT 10,
    min_height int(11) NOT NULL DEFAULT 10,
    active tinyint(1) NOT NULL DEFAULT 1,
    created datetime NOT NULL,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  pwg_query("ALTER TABLE `$profiles` MODIFY watermark_file varchar(255) DEFAULT NULL");

  if (!bratonien_tools_column_exists($profiles, 'scale_percent'))
  {
    pwg_query("ALTER TABLE `$profiles` ADD scale_percent decimal(8,2) NOT NULL DEFAULT 100.00 AFTER watermark_file");
  }
  if (!bratonien_tools_column_exists($profiles, 'xrepeat'))
  {
    pwg_query("ALTER TABLE `$profiles` ADD xrepeat int(11) NOT NULL DEFAULT 0 AFTER ypos");
  }
  if (!bratonien_tools_column_exists($profiles, 'yrepeat'))
  {
    pwg_query("ALTER TABLE `$profiles` ADD yrepeat int(11) NOT NULL DEFAULT 0 AFTER xrepeat");
  }
  if (!bratonien_tools_column_exists($profiles, 'active'))
  {
    pwg_query("ALTER TABLE `$profiles` ADD active tinyint(1) NOT NULL DEFAULT 1 AFTER min_height");
  }

  pwg_query("CREATE TABLE IF NOT EXISTS `$rules` (
    id int(11) NOT NULL AUTO_INCREMENT,
    category_id int(11) NOT NULL,
    mode enum('inherit','profile','disabled') NOT NULL DEFAULT 'inherit',
    profile_id int(11) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY category_id (category_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  if (function_exists('bratonien_tools_create_album_shares_table'))
  {
    bratonien_tools_create_album_shares_table();
  }
}

function bratonien_tools_drop_tables()
{
  pwg_query('DROP TABLE IF EXISTS `'.bratonien_tools_table('watermark_profiles').'`');
  pwg_query('DROP TABLE IF EXISTS `'.bratonien_tools_table('watermark_rules').'`');

  if (function_exists('bratonien_tools_drop_album_shares_table'))
  {
    bratonien_tools_drop_album_shares_table();
  }
}
