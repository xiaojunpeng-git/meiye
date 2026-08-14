-- upgrade_key: 20260814-002-cashier-v3-labor-performance-fee-split
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_performance_fact' AND COLUMN_NAME='labor_fee_amount_cents')=0,
  'ALTER TABLE `eb_cashier_v3_performance_fact` ADD COLUMN `labor_fee_amount_cents` bigint(20) NOT NULL DEFAULT 0 AFTER `amount_cents`',
  'SELECT ''performance_labor_fee_already_present'' AS apply_result');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='labor_fee_amount_cents')=0,
  'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `labor_fee_amount_cents` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `labor_amount_cents`',
  'SELECT ''service_labor_fee_already_present'' AS apply_result');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SELECT 'APPLY_OK' AS apply_result;
