-- upgrade_key: 20260917-005-cashier-v3-service-customer-snapshot
-- MySQL 5.6 compatible, repeatable and additive only. No historical rows are updated.
SET NAMES utf8mb4;
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=@db
     AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact'
     AND COLUMN_NAME='friend_counts_as_customer')=0,
  'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `friend_counts_as_customer` tinyint(3) unsigned NOT NULL DEFAULT ''1'' COMMENT ''服务对象客数快照：本人或朋友算=1，朋友不算=0'' AFTER `service_object`',
  'SELECT ''friend_counts_as_customer already exists'' AS apply_result'
);
PREPARE service_customer_snapshot_stmt FROM @sql;
EXECUTE service_customer_snapshot_stmt;
DEALLOCATE PREPARE service_customer_snapshot_stmt;

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT
  '20260917-005-cashier-v3-service-customer-snapshot',
  '收银 V3 服务对象客数快照',
  'database/upgrades/2026-09-17-收银V3服务对象客数快照/02-正式升级.sql',
  '',
  'WORKTREE',
  NOW(),
  'Codex',
  '仅新增服务事实朋友算客快照字段，未处理历史业务数据'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20260917-005-cashier-v3-service-customer-snapshot'
);

SELECT 'APPLY_OK' AS apply_result;
