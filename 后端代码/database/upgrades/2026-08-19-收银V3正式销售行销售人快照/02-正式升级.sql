-- Checkout request lines already freeze salesperson assignments. Persist the
-- same snapshot on the formal sales line so final settlement remains auditable.
SET @has_column := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'eb_cashier_v3_sales_order_line'
    AND COLUMN_NAME = 'salespeople_snapshot_json'
);
SET @sql := IF(
  @has_column = 0,
  'ALTER TABLE `eb_cashier_v3_sales_order_line` ADD COLUMN `salespeople_snapshot_json` MEDIUMTEXT NULL AFTER `craftsmen_snapshot_json`',
  'SELECT ''salespeople_snapshot_json already exists'' AS apply_note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
