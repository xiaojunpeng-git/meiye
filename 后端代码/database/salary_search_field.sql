-- 报表自定义搜索项（执行一次）
CREATE TABLE IF NOT EXISTS `eb_salary_search_field` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(64) NOT NULL DEFAULT '' COMMENT 'SQL占位符参数名，与SQL中{{key}}一致',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '搜索项显示名称',
  `input_type` tinyint(3) NOT NULL DEFAULT '1' COMMENT '1文本 3单日 4下拉 5门店 6日期区间',
  `info` varchar(500) DEFAULT '' COMMENT '下拉选项，逗号分隔',
  `sort` int(11) NOT NULL DEFAULT '0',
  `is_show` tinyint(1) NOT NULL DEFAULT '1',
  `table_ids` varchar(255) NOT NULL DEFAULT '' COMMENT '关联 salary_table.id',
  PRIMARY KEY (`id`),
  KEY `idx_table_ids` (`table_ids`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报表自定义搜索项';
