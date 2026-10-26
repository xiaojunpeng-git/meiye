-- Checkout request lines own the salesperson snapshot used by final settlement.
-- This is an idempotent MySQL 5.6 compatible upgrade.
SET @has_column := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'eb_cashier_v3_checkout_line_draft'
    AND COLUMN_NAME = 'salespeople_snapshot_json'
);
SET @sql := IF(
  @has_column = 0,
  'ALTER TABLE `eb_cashier_v3_checkout_line_draft` ADD COLUMN `salespeople_snapshot_json` MEDIUMTEXT NULL AFTER `craftsmen_snapshot_json`',
  'SELECT ''salespeople_snapshot_json already exists'' AS apply_note'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
