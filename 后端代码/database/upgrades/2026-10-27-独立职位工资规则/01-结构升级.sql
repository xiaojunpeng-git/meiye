-- 仅新增空配置表；不迁移、覆盖或重算历史工资、提成与业务事实。
CREATE TABLE IF NOT EXISTS `eb_position_salary_rule` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `position_id` int unsigned NOT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `commission` decimal(9,4) NOT NULL DEFAULT 0,
  `cate_ids` varchar(2000) NOT NULL DEFAULT '',
  `range` varchar(100) NOT NULL DEFAULT '',
  `type` tinyint unsigned NOT NULL DEFAULT 0,
  `range_type` tinyint unsigned NOT NULL DEFAULT 0,
  `time_type` tinyint unsigned NOT NULL DEFAULT 1,
  `has_recharge` tinyint unsigned NOT NULL DEFAULT 1,
  `version` int unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`), KEY `position_rules` (`position_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='独立职位工资规则；旧职级配置只读保留';
CREATE TABLE IF NOT EXISTS `eb_position_salary_rule_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` int unsigned NOT NULL,
  `operator_id` int unsigned NOT NULL DEFAULT 0,
  `action` varchar(20) NOT NULL,
  `before_json` text NOT NULL,
  `after_json` text NOT NULL,
  `created_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `rule_audit` (`rule_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='独立职位工资配置修改审计';
