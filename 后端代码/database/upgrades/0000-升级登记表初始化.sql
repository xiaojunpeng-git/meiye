-- upgrade_key: 0000-database-upgrade-log
-- 用途：在 007、008、012、rh 四个数据库中分别建立数据库升级成功记录表。
-- 注意：本文件当前仅已创建，尚未在任何数据库执行；执行前必须备份并确认表前缀为 eb_。

CREATE TABLE IF NOT EXISTS `eb_database_upgrade_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `upgrade_key` varchar(100) NOT NULL COMMENT '全局唯一升级键',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '升级名称',
  `task_file` varchar(500) NOT NULL DEFAULT '' COMMENT '对应任务文件',
  `sql_checksum` char(64) NOT NULL DEFAULT '' COMMENT '正式升级SQL的SHA-256',
  `code_version` varchar(100) NOT NULL DEFAULT '' COMMENT '对应代码版本/commit/tag',
  `executed_at` datetime NOT NULL COMMENT '验证通过时间',
  `executed_by` varchar(100) NOT NULL DEFAULT '' COMMENT '执行人或执行AI',
  `result_note` varchar(500) NOT NULL DEFAULT '' COMMENT '备份、验证或备注',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_upgrade_key` (`upgrade_key`),
  KEY `idx_executed_at` (`executed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据库升级成功记录';

-- 本初始化脚本成功并验证后，再由执行者写入 0000 自身记录。
-- sql_checksum 必须替换成执行时本文件的真实 SHA-256，不得保留占位文字。
-- INSERT INTO `eb_database_upgrade_log`
-- (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
-- VALUES
-- ('0000-database-upgrade-log','数据库升级登记表初始化','后端代码/database/upgrades/0000-升级登记表初始化.sql','<真实SHA-256>','docs-2026-07-14',NOW(),'<执行人>','<备份与验证证据>');
