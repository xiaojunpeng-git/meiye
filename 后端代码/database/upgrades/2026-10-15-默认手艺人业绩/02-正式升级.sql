-- upgrade_key: 20261015-001-craftsman-performance-default
-- 旧项目和旧卡保留原默认口径；新字段不回写历史人员业绩事实。
SET NAMES utf8mb4;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_project_performance_rule' AND COLUMN_NAME='craftsman_default_unit_amount_cents')=0,
  'ALTER TABLE `eb_cashier_v3_project_performance_rule` ADD COLUMN `craftsman_default_unit_amount_cents` bigint(20) unsigned NULL AFTER `labor_configured_unit_amount_cents`',
  'SELECT ''project_default_already_present'' AS apply_result');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='craftsman_default_mode')=0,
  'ALTER TABLE `eb_store_card_related` ADD COLUMN `craftsman_default_mode` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''default_unit_price'' AFTER `labor_fee_cents`',
  'SELECT ''card_default_mode_already_present'' AS apply_result');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='craftsman_default_unit_amount_cents')=0,
  'ALTER TABLE `eb_store_card_related` ADD COLUMN `craftsman_default_unit_amount_cents` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `craftsman_default_mode`',
  'SELECT ''card_default_amount_already_present'' AS apply_result');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
