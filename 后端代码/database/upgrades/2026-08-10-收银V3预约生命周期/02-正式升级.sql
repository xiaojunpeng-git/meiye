-- upgrade_key: 20260810-003-cashier-v3-reservation-lifecycle-v1
-- MySQL 5.6 compatible. Preserve historic reservation lines; SKU is required
-- for future edits to revalidate the exact originally selected project spec.
SET NAMES utf8mb4;

SET @reservation_lifecycle_db := DATABASE();
SET @reservation_line_sku_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @reservation_lifecycle_db
    AND TABLE_NAME = 'eb_cashier_v3_reservation_line'
    AND COLUMN_NAME = 'sku_id'
);
SET @reservation_line_sku_sql := IF(
  @reservation_line_sku_exists = 0,
  'ALTER TABLE `eb_cashier_v3_reservation_line` ADD COLUMN `sku_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `project_id`',
  'SELECT ''sku_id already exists'' AS migration_note'
);
PREPARE reservation_lifecycle_stmt FROM @reservation_line_sku_sql;
EXECUTE reservation_lifecycle_stmt;
DEALLOCATE PREPARE reservation_lifecycle_stmt;

SELECT 'APPLY_OK' AS apply_result;
