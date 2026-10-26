-- upgrade_key: 20260811-001-cashier-v3-line-coupon-snapshot
-- Read-only. Old rows remain coupon-free and are not backfilled.
SET NAMES utf8mb4;
SET @clc_db := DATABASE();
SET @clc_key := '20260811-001-cashier-v3-line-coupon-snapshot';
SET @clc_failures := 0;

SELECT COUNT(*) INTO @clc_registered
FROM eb_database_upgrade_log WHERE upgrade_key=@clc_key;

SELECT COUNT(*) INTO @clc_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@clc_db AND ENGINE='InnoDB'
  AND TABLE_NAME IN (
    'eb_cashier_v3_workspace_line','eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_sales_order_line','eb_cashier_v3_sale_fact'
  );

SET @clc_failures := @clc_failures
  + IF(@clc_registered IN (0,1),0,1)
  + IF(@clc_tables=4,0,1);

SELECT @clc_registered AS upgrade_log_rows,
       @clc_tables AS dependency_table_count,
       @clc_failures AS precheck_failure_count;

SET @clc_finish_sql := IF(@clc_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_LINE_COUPON_PRECHECK_FAILED');
PREPARE clc_finish_stmt FROM @clc_finish_sql;
EXECUTE clc_finish_stmt;
DEALLOCATE PREPARE clc_finish_stmt;
