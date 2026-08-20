SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft'
     AND COLUMN_NAME='card_purchase_snapshot_json') = 0,
  'ALTER TABLE `eb_cashier_v3_checkout_line_draft` ADD COLUMN `card_purchase_snapshot_json` MEDIUMTEXT NULL AFTER `manual_labor_fee_cents`',
  'SELECT ''checkout card purchase snapshot already exists'' AS apply_note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_sales_order_line'
     AND COLUMN_NAME='card_purchase_snapshot_json') = 0,
  'ALTER TABLE `eb_cashier_v3_sales_order_line` ADD COLUMN `card_purchase_snapshot_json` MEDIUMTEXT NULL AFTER `manual_labor_fee_cents`',
  'SELECT ''sales order card purchase snapshot already exists'' AS apply_note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
