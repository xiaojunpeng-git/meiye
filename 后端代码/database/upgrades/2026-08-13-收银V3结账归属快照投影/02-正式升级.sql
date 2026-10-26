-- upgrade_key: 20260813-003-cashier-v3-attribution-snapshot-projection
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @has_guide := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='guide_selections_json');
SET @sql := IF(@has_guide=0,
  'ALTER TABLE `eb_cashier_v3_checkout_line_draft` ADD COLUMN `guide_selections_json` MEDIUMTEXT NULL AFTER `craftsmen_snapshot_json`',
  'SELECT ''GUIDE_COLUMN_ALREADY_PRESENT'' AS apply_result');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_manager := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='sales_manager_selections_json');
SET @sql := IF(@has_manager=0,
  'ALTER TABLE `eb_cashier_v3_checkout_line_draft` ADD COLUMN `sales_manager_selections_json` MEDIUMTEXT NULL AFTER `guide_selections_json`',
  'SELECT ''MANAGER_COLUMN_ALREADY_PRESENT'' AS apply_result');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SELECT 'APPLY_OK' AS apply_result;
