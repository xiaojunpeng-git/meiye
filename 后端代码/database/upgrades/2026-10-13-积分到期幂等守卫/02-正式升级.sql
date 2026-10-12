-- upgrade_key: 20261013-001-integral-expiry-guard
-- MySQL 5.7；通用结构，不开启任何实例的积分到期配置或定时任务。
CREATE TABLE IF NOT EXISTS `eb_user_integral_expiry` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL COMMENT '会员 ID',
  `period_start` int(10) unsigned NOT NULL COMMENT '积分取得期间开始秒',
  `period_end` int(10) unsigned NOT NULL COMMENT '积分取得期间结束秒',
  `clear_time` int(10) unsigned NOT NULL COMMENT '业务清算日零点秒',
  `number` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '本次实际到期扣减积分',
  `bill_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'system_clear 积分流水 ID，零扣减为 0',
  `recorded_at` int(10) unsigned NOT NULL COMMENT '真实落库秒',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_uid_period_start` (`uid`, `period_start`),
  KEY `idx_period_start` (`period_start`),
  KEY `idx_bill_id` (`bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员积分到期逐期间幂等守卫';

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20261013-001-integral-expiry-guard',
  '会员积分到期逐期间幂等守卫',
  'database/upgrades/2026-10-13-积分到期幂等守卫/02-正式升级.sql',
  '', 'LOCAL', NOW(), 'Codex', '仅建通用幂等守卫表；未开启客户实例定时任务'
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20261013-001-integral-expiry-guard'
);
