SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_workspace_line' AND COLUMN_NAME='inventory_outbound_required')=0,
  'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `inventory_outbound_required` tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER `is_presale`','SELECT ''workspace inventory rule exists'''); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='inventory_outbound_required')=0,
  'ALTER TABLE `eb_cashier_v3_checkout_line_draft` ADD COLUMN `inventory_outbound_required` tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER `is_presale`','SELECT ''checkout inventory rule exists'''); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_sales_order_line' AND COLUMN_NAME='inventory_outbound_required')=0,
  'ALTER TABLE `eb_cashier_v3_sales_order_line` ADD COLUMN `inventory_outbound_required` tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER `is_presale`','SELECT ''sales order inventory rule exists'''); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_sale_fact' AND COLUMN_NAME='inventory_outbound_required')=0,
  'ALTER TABLE `eb_cashier_v3_sale_fact` ADD COLUMN `inventory_outbound_required` tinyint(1) unsigned NOT NULL DEFAULT 1','SELECT ''sale fact inventory rule exists'''); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
