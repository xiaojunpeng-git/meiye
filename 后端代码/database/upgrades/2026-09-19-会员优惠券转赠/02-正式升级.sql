-- upgrade_key: 20260919-001-member-coupon-transfer
SET NAMES utf8mb4;
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db
     AND TABLE_NAME = 'eb_store_coupon_issue'
     AND COLUMN_NAME = 'allow_transfer') = 0,
  'ALTER TABLE `eb_store_coupon_issue` ADD COLUMN `allow_transfer` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT ''是否允许会员转赠:0否,1是'' AFTER `rule`',
  'SELECT ''allow_transfer_already_present'' AS apply_result'
);
PREPARE allow_transfer_stmt FROM @sql;
EXECUTE allow_transfer_stmt;
DEALLOCATE PREPARE allow_transfer_stmt;

CREATE TABLE IF NOT EXISTS `eb_store_coupon_transfer` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '转赠记录ID',
  `transfer_no` varchar(40) NOT NULL COMMENT '转赠业务号',
  `request_id` varchar(64) NOT NULL COMMENT '幂等请求标识',
  `coupon_user_id` int(11) NOT NULL COMMENT '优惠券实例ID',
  `coupon_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '优惠券发放配置ID',
  `coupon_title` varchar(255) NOT NULL DEFAULT '' COMMENT '转赠时券名称快照',
  `from_uid` int(11) unsigned NOT NULL COMMENT '转出会员ID',
  `to_uid` int(11) unsigned NOT NULL COMMENT '接收会员ID',
  `coupon_start_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '券开始时间快照',
  `coupon_end_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '券结束时间快照',
  `occurred_at` int(11) unsigned NOT NULL COMMENT '转赠业务发生时间',
  `recorded_at` int(11) unsigned NOT NULL COMMENT '记录写入时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_transfer_no` (`transfer_no`),
  UNIQUE KEY `uk_request_id` (`request_id`),
  UNIQUE KEY `uk_coupon_user_id` (`coupon_user_id`),
  KEY `idx_from_uid_time` (`from_uid`, `occurred_at`, `id`),
  KEY `idx_to_uid_time` (`to_uid`, `occurred_at`, `id`),
  KEY `idx_coupon_id` (`coupon_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员优惠券转赠永久审计记录';

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`, `title`, `task_file`, `sql_checksum`, `code_version`, `executed_at`, `executed_by`, `result_note`)
SELECT
  '20260919-001-member-coupon-transfer',
  '会员优惠券转赠',
  'database/upgrades/2026-09-19-会员优惠券转赠/02-正式升级.sql',
  '',
  'WORKTREE',
  NOW(),
  'Codex',
  '新增券转赠开关和永久审计表；不回填、不改动历史券归属'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key` = '20260919-001-member-coupon-transfer'
);

SELECT 'APPLY_OK' AS apply_result;
