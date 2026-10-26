-- upgrade_key: 20260730-001-cashier-v3-sale-sku-freeze-v1
-- MySQL 5.6.51 compatible and idempotent.
SET NAMES utf8mb4;

SET @sku_column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_checkout_line_draft'
    AND COLUMN_NAME='catalog_sku_id'
);
SET @sku_ddl := IF(
  @sku_column_exists=0,
  'ALTER TABLE `eb_cashier_v3_checkout_line_draft` ADD COLUMN `catalog_sku_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `source_id`',
  'SELECT ''checkout_line_draft.catalog_sku_id already exists'' AS apply_note'
);
PREPARE sku_stmt FROM @sku_ddl;
EXECUTE sku_stmt;
DEALLOCATE PREPARE sku_stmt;

SET @sku_column_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_sales_order_line'
    AND COLUMN_NAME='catalog_sku_id'
);
SET @sku_ddl := IF(
  @sku_column_exists=0,
  'ALTER TABLE `eb_cashier_v3_sales_order_line` ADD COLUMN `catalog_sku_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `item_id`',
  'SELECT ''sales_order_line.catalog_sku_id already exists'' AS apply_note'
);
PREPARE sku_stmt FROM @sku_ddl;
EXECUTE sku_stmt;
DEALLOCATE PREPARE sku_stmt;

SELECT 'APPLY_OK' AS apply_result;
