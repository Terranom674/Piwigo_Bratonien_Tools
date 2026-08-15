<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_table($name)
{
  return $GLOBALS['prefixeTable'] . 'bratonien_tools_' . $name;
}

function bratonien_tools_create_tables()
{
  $profiles = bratonien_tools_table('watermark_profiles');
  $rules = bratonien_tools_table('watermark_rules');

  pwg_query("CREATE TABLE IF NOT EXISTS `$profiles` (
    id int(11) NOT NULL AUTO_INCREMENT,
    name varchar(255) NOT NULL,
    watermark_file varchar(255) NOT NULL,
    xpos int(11) NOT NULL DEFAULT 90,
    ypos int(11) NOT NULL DEFAULT 90,
    opacity int(11) NOT NULL DEFAULT 35,
    min_width int(11) NOT NULL DEFAULT 10,
    min_height int(11) NOT NULL DEFAULT 10,
    created datetime NOT NULL,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  pwg_query("CREATE TABLE IF NOT EXISTS `$rules` (
    id int(11) NOT NULL AUTO_INCREMENT,
    category_id int(11) NOT NULL,
    mode enum('inherit','profile','disabled') NOT NULL DEFAULT 'inherit',
    profile_id int(11) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY category_id (category_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function bratonien_tools_drop_tables()
{
  pwg_query('DROP TABLE IF EXISTS `'.bratonien_tools_table('watermark_profiles').'`');
  pwg_query('DROP TABLE IF EXISTS `'.bratonien_tools_table('watermark_rules').'`');
}
