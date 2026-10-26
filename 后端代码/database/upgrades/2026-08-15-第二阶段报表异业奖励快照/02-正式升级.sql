-- upgrade_key: 20260815-001-phase-two-report-cross-industry-reward
-- MySQL 5.6 compatible, repeatable and non-destructive.
SET NAMES utf8mb4;
SET @reward_db := DATABASE();

SET @reward_table := 'eb_cashier_v3_checkout_business_source_selection';
SET @reward_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reward_db AND TABLE_NAME=@reward_table AND COLUMN_NAME='reward_amount_cents')=0,
  'ALTER TABLE `eb_cashier_v3_checkout_business_source_selection` ADD COLUMN `reward_amount_cents` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `source_label_snapshot`',
  'SELECT ''checkout reward_amount_cents already exists'' AS apply_note');
PREPARE reward_stmt FROM @reward_sql;
EXECUTE reward_stmt;
DEALLOCATE PREPARE reward_stmt;

SET @reward_table := 'eb_cashier_v3_sales_order';
SET @reward_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reward_db AND TABLE_NAME=@reward_table AND COLUMN_NAME='reward_amount_cents')=0,
  'ALTER TABLE `eb_cashier_v3_sales_order` ADD COLUMN `reward_amount_cents` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `business_source_label_snapshot`',
  'SELECT ''sales order reward_amount_cents already exists'' AS apply_note');
PREPARE reward_stmt FROM @reward_sql;
EXECUTE reward_stmt;
DEALLOCATE PREPARE reward_stmt;

SELECT 'APPLY_OK' AS apply_result;
