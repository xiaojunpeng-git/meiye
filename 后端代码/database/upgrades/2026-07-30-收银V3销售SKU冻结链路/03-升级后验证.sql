-- upgrade_key: 20260730-001-cashier-v3-sale-sku-freeze-v1
-- Read-only exact schema validation. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @sku_db := DATABASE();
SET @sku_failures := 0;

SELECT COUNT(*) INTO @sku_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@sku_db AND ENGINE='InnoDB'
  AND TABLE_NAME IN ('eb_cashier_v3_checkout_line_draft','eb_cashier_v3_sales_order_line');

SELECT COUNT(*) INTO @sku_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@sku_db AND (
  (TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='catalog_sku_id'
    AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
  OR (TABLE_NAME='eb_cashier_v3_sales_order_line' AND COLUMN_NAME='catalog_sku_id'
    AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
);

SET @sku_failures := @sku_failures
  + IF(@sku_tables=2,0,1)
  + IF(@sku_columns=2,0,1);

SELECT IF(@sku_failures=0,'POSTCHECK_OK','POSTCHECK_FAILED') AS postcheck_result,
  @sku_failures AS failure_count,
  @sku_tables AS target_table_count,
  @sku_columns AS exact_sku_column_count;

SET @sku_abort_sql := IF(
  @sku_failures=0,
  'SELECT ''POSTCHECK_CONTINUE'' AS gate',
  'SELECT * FROM STOP_CASHIER_V3_SALE_SKU_POSTCHECK_FAILED'
);
PREPARE sku_postcheck_stmt FROM @sku_abort_sql;
EXECUTE sku_postcheck_stmt;
DEALLOCATE PREPARE sku_postcheck_stmt;
