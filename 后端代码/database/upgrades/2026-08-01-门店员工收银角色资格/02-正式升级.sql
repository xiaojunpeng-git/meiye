-- upgrade_key: 20260801-001-store-staff-cashier-role-eligibility
-- Run 01 before and 03 after this script. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @ssre_db := DATABASE();

SELECT COUNT(*) INTO @ssre_sales_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ssre_db AND TABLE_NAME='eb_system_store_staff'
  AND COLUMN_NAME='cashier_salesperson_enabled';
SET @ssre_sql := IF(@ssre_sales_column=0,
  'ALTER TABLE `eb_system_store_staff` ADD COLUMN `cashier_salesperson_enabled` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT ''收银可作为销售人 0否1是'' AFTER `can_choose`',
  'SELECT ''SALESPERSON_COLUMN_ALREADY_PRESENT'' AS apply_result');
PREPARE ssre_stmt FROM @ssre_sql;
EXECUTE ssre_stmt;
DEALLOCATE PREPARE ssre_stmt;

SELECT COUNT(*) INTO @ssre_craft_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ssre_db AND TABLE_NAME='eb_system_store_staff'
  AND COLUMN_NAME='cashier_craftsman_enabled';
SET @ssre_sql := IF(@ssre_craft_column=0,
  'ALTER TABLE `eb_system_store_staff` ADD COLUMN `cashier_craftsman_enabled` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT ''收银可作为手艺人 0否1是'' AFTER `cashier_salesperson_enabled`',
  'SELECT ''CRAFTSMAN_COLUMN_ALREADY_PRESENT'' AS apply_result');
PREPARE ssre_stmt FROM @ssre_sql;
EXECUTE ssre_stmt;
DEALLOCATE PREPARE ssre_stmt;

SELECT 'APPLY_OK' AS apply_result;
