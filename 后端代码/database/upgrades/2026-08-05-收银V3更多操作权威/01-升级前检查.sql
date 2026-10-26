-- upgrade_key: 20260805-001-cashier-v3-more-actions-authority
-- Read-only. This upgrade adds defaults only and never backfills old rows.
SET NAMES utf8mb4;
SET @cma_db := DATABASE();
SET @cma_key := '20260805-001-cashier-v3-more-actions-authority';
SET @cma_failures := 0;

SELECT COUNT(*) INTO @cma_registered
FROM eb_database_upgrade_log WHERE upgrade_key=@cma_key;

SELECT COUNT(*) INTO @cma_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cma_db AND ENGINE='InnoDB'
  AND TABLE_NAME IN (
    'eb_cashier_v3_workspace_draft','eb_cashier_v3_workspace_line',
    'eb_cashier_v3_checkout_request','eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_sales_order','eb_cashier_v3_sales_order_line',
    'eb_cashier_v3_sale_fact'
  );

SELECT COUNT(*) INTO @cma_cost_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cma_db
  AND TABLE_NAME='eb_store_product_attr_value' AND COLUMN_NAME='cost';

SET @cma_failures := @cma_failures
  + IF(@cma_registered IN (0,1),0,1)
  + IF(@cma_tables=7,0,1)
  + IF(@cma_cost_column=1,0,1);

SELECT @cma_registered AS upgrade_log_rows,
       @cma_tables AS dependency_table_count,
       @cma_cost_column AS sku_cost_column_count,
       @cma_failures AS precheck_failure_count;

SET @cma_finish_sql := IF(@cma_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_MORE_ACTIONS_PRECHECK_FAILED');
PREPARE cma_finish_stmt FROM @cma_finish_sql;
EXECUTE cma_finish_stmt;
DEALLOCATE PREPARE cma_finish_stmt;
