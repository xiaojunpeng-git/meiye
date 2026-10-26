-- upgrade_key: 20260814-001-cashier-v3-manual-labor-fee-override
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_workspace_line' AND COLUMN_NAME='manual_labor_fee_cents')=0,
  'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `manual_labor_fee_cents` bigint(20) unsigned NULL AFTER `sales_manager_selections_json`',
  'SELECT ''workspace_column_already_present'' AS apply_result');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='manual_labor_fee_cents')=0,
  'ALTER TABLE `eb_cashier_v3_checkout_line_draft` ADD COLUMN `manual_labor_fee_cents` bigint(20) unsigned NULL AFTER `sales_manager_selections_json`',
  'SELECT ''checkout_column_already_present'' AS apply_result');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_sales_order_line' AND COLUMN_NAME='manual_labor_fee_cents')=0,
  'ALTER TABLE `eb_cashier_v3_sales_order_line` ADD COLUMN `manual_labor_fee_cents` bigint(20) unsigned NULL',
  'SELECT ''sales_order_column_already_present'' AS apply_result');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='labor_amount_cents')=0,
  'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `labor_amount_cents` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `is_experience`',
  'SELECT ''service_labor_amount_already_present'' AS apply_result');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='labor_mode')=0,
  'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `labor_mode` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''project_rule'' AFTER `labor_amount_cents`',
  'SELECT ''service_labor_mode_already_present'' AS apply_result');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SELECT 'APPLY_OK' AS apply_result;
