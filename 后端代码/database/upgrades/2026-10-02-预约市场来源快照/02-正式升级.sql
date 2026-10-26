-- upgrade_key: 20261002-001-reservation-market-source-snapshot
-- MySQL 5.6 compatible. Existing reservations and service facts keep empty attribution.
SET NAMES utf8mb4;
SET @source_db := DATABASE();

SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='business_source_primary_id')=0, 'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `business_source_primary_id` bigint(20) unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;

SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='business_source_primary_name_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `business_source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='business_source_secondary_id')=0, 'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `business_source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='business_source_secondary_name_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `business_source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='business_source_label_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `business_source_label_snapshot` varchar(140) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='source_attribution_type_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `source_attribution_type_snapshot` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''other''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;

SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='business_source_primary_id')=0, 'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `business_source_primary_id` bigint(20) unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='business_source_primary_name_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `business_source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='business_source_secondary_id')=0, 'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `business_source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='business_source_secondary_name_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `business_source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='business_source_label_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `business_source_label_snapshot` varchar(140) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;
SET @source_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@source_db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='source_attribution_type_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `source_attribution_type_snapshot` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''other''', 'SELECT 1');
PREPARE source_stmt FROM @source_sql; EXECUTE source_stmt; DEALLOCATE PREPARE source_stmt;

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT
  '20261002-001-reservation-market-source-snapshot',
  '预约市场来源与服务事实快照',
  'database/upgrades/2026-10-02-预约市场来源快照/02-正式升级.sql',
  '', 'LOCAL', NOW(), 'Codex',
  '只增加来源快照字段，不回填历史预约或服务记录'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20261002-001-reservation-market-source-snapshot'
);
