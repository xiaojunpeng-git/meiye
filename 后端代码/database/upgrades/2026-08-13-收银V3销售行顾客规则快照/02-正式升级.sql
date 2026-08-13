SET NAMES utf8mb4;
SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='friend_counts_as_customer')=0,
  'ALTER TABLE `eb_cashier_v3_checkout_line_draft` ADD COLUMN `friend_counts_as_customer` tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER `entitlement_actual_amount_cents`','SELECT ''checkout friend field exists'''); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='is_presale')=0,
  'ALTER TABLE `eb_cashier_v3_checkout_line_draft` ADD COLUMN `is_presale` tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER `friend_counts_as_customer`','SELECT ''checkout presale field exists'''); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @tables := 'eb_cashier_v3_sales_order_line,eb_cashier_v3_sale_fact';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_workspace_line' AND COLUMN_NAME='friend_counts_as_customer')=0,
  'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `friend_counts_as_customer` tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER `sales_manager_selections_json`, ADD COLUMN `is_presale` tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER `friend_counts_as_customer`','SELECT ''workspace rule fields exist'''); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_sales_order_line' AND COLUMN_NAME='friend_counts_as_customer')=0,
  'ALTER TABLE `eb_cashier_v3_sales_order_line` ADD COLUMN `friend_counts_as_customer` tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER `configured_cost_cents`, ADD COLUMN `is_presale` tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER `friend_counts_as_customer`','SELECT ''sales order rule fields exist'''); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_sale_fact' AND COLUMN_NAME='friend_counts_as_customer')=0,
  'ALTER TABLE `eb_cashier_v3_sale_fact` ADD COLUMN `friend_counts_as_customer` tinyint(1) unsigned NOT NULL DEFAULT 1, ADD COLUMN `is_presale` tinyint(1) unsigned NOT NULL DEFAULT 0','SELECT ''sale fact rule fields exist'''); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SELECT 'APPLY_OK' AS apply_result;
