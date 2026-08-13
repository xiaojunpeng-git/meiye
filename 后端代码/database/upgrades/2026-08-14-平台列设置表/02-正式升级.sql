-- upgrade_key: 20260814-001-admin-table-column
CREATE TABLE IF NOT EXISTS `eb_admin_table_column` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `admin_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1总后台 2门店后台',
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '管理员/店员ID',
  `table_key` varchar(64) NOT NULL DEFAULT '' COMMENT '表标识',
  `columns_json` mediumtext COMMENT '列配置JSON',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_table` (`admin_type`,`admin_id`,`table_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表格列配置';
