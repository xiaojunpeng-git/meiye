-- upgrade_key: 20260730-001-cashier-v3-sale-sku-freeze-v1
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @sku_db := DATABASE();
SET @sku_failures := 0;

SELECT COUNT(*) INTO @sku_upgrade_log
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@sku_db AND TABLE_NAME='eb_database_upgrade_log' AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @sku_authorities
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@sku_db AND ENGINE='InnoDB'
  AND TABLE_NAME IN ('eb_cashier_v3_checkout_line_draft','eb_cashier_v3_sales_order_line');

SELECT COUNT(*) INTO @sku_existing_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@sku_db
  AND (TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='catalog_sku_id'
    OR TABLE_NAME='eb_cashier_v3_sales_order_line' AND COLUMN_NAME='catalog_sku_id');

SELECT COUNT(*) INTO @sku_valid_existing_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@sku_db AND (
  (TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='catalog_sku_id'
    AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
  OR (TABLE_NAME='eb_cashier_v3_sales_order_line' AND COLUMN_NAME='catalog_sku_id'
    AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
);

SET @sku_failures := @sku_failures
  + IF(@sku_upgrade_log=1,0,1)
  + IF(@sku_authorities=2,0,1)
  + IF(@sku_existing_columns IN (0,2),0,1)
  + IF(@sku_existing_columns=0 OR @sku_valid_existing_columns=2,0,1);

SELECT IF(@sku_failures=0,'PRECHECK_OK','PRECHECK_FAILED') AS precheck_result,
  @sku_failures AS failure_count,
  @sku_authorities AS authority_table_count,
  @sku_existing_columns AS existing_sku_column_count,
  @sku_valid_existing_columns AS valid_existing_sku_column_count;

SET @sku_abort_sql := IF(
  @sku_failures=0,
  'SELECT ''PRECHECK_CONTINUE'' AS gate',
  'SELECT * FROM STOP_CASHIER_V3_SALE_SKU_PRECHECK_FAILED'
);
PREPARE sku_precheck_stmt FROM @sku_abort_sql;
EXECUTE sku_precheck_stmt;
DEALLOCATE PREPARE sku_precheck_stmt;
