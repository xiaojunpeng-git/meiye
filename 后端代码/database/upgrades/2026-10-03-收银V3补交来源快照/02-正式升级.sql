-- upgrade_key: 20261003-001-cashier-v3-repayment-source-snapshot
-- MySQL 5.6 compatible; defaults preserve unknown historical attribution without guessing.
SET NAMES utf8mb4;
SET @source_db := DATABASE();

SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_debt_repayment' AND COLUMN_NAME='business_source_primary_id')=0, 'ALTER TABLE `eb_cashier_v3_debt_repayment` ADD COLUMN `business_source_primary_id` bigint(20) unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_debt_repayment' AND COLUMN_NAME='business_source_primary_name_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_debt_repayment` ADD COLUMN `business_source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_debt_repayment' AND COLUMN_NAME='business_source_secondary_id')=0, 'ALTER TABLE `eb_cashier_v3_debt_repayment` ADD COLUMN `business_source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_debt_repayment' AND COLUMN_NAME='business_source_secondary_name_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_debt_repayment` ADD COLUMN `business_source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_debt_repayment' AND COLUMN_NAME='business_source_label_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_debt_repayment` ADD COLUMN `business_source_label_snapshot` varchar(140) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;

SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_repayment' AND COLUMN_NAME='business_source_primary_id')=0, 'ALTER TABLE `eb_cashier_v3_recharge_debt_repayment` ADD COLUMN `business_source_primary_id` bigint(20) unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_repayment' AND COLUMN_NAME='business_source_primary_name_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_recharge_debt_repayment` ADD COLUMN `business_source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_repayment' AND COLUMN_NAME='business_source_secondary_id')=0, 'ALTER TABLE `eb_cashier_v3_recharge_debt_repayment` ADD COLUMN `business_source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_repayment' AND COLUMN_NAME='business_source_secondary_name_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_recharge_debt_repayment` ADD COLUMN `business_source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_repayment' AND COLUMN_NAME='business_source_label_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_recharge_debt_repayment` ADD COLUMN `business_source_label_snapshot` varchar(140) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20261003-001-cashier-v3-repayment-source-snapshot',
  '收银V3补交来源快照',
  'database/upgrades/2026-10-03-收银V3补交来源快照/02-正式升级.sql',
  '', 'LOCAL', NOW(), 'Codex', '两种补交主单增加来源快照列；历史保持空值'
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20261003-001-cashier-v3-repayment-source-snapshot'
);
