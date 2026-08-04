-- upgrade_key: 20260804-002-cashier-v3-sales-order-craftsmen-snapshot-v1
-- Read-only exact schema and non-project invariant verification. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @socs_db := DATABASE();
SET @socs_failures := 0;

SELECT COUNT(*) INTO @socs_exact_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@socs_db
  AND TABLE_NAME IN ('eb_cashier_v3_checkout_line_draft','eb_cashier_v3_sales_order_line')
  AND COLUMN_NAME='craftsmen_snapshot_json'
  AND DATA_TYPE='mediumtext'
  AND IS_NULLABLE='NO'
  AND COLUMN_DEFAULT IS NULL;

-- Historical rows remain untouched by this schema-only package. Their empty
-- MEDIUMTEXT value is read as [] by the V3 snapshot decoder. New non-project
-- rows are explicitly written as [] by the application.
SELECT COUNT(*) INTO @socs_invalid_checkout_non_project_rows
FROM eb_cashier_v3_checkout_line_draft
WHERE source_type NOT IN ('project')
  AND craftsmen_snapshot_json NOT IN ('','[]');

SELECT COUNT(*) INTO @socs_invalid_order_non_project_rows
FROM eb_cashier_v3_sales_order_line
WHERE item_type NOT IN ('project')
  AND craftsmen_snapshot_json NOT IN ('','[]');

SET @socs_failures := @socs_failures
  + IF(@socs_exact_columns=2,0,1)
  + IF(@socs_invalid_checkout_non_project_rows=0,0,1)
  + IF(@socs_invalid_order_non_project_rows=0,0,1);

SELECT @socs_exact_columns AS exact_snapshot_column_count,
  @socs_invalid_checkout_non_project_rows AS invalid_checkout_non_project_row_count,
  @socs_invalid_order_non_project_rows AS invalid_order_non_project_row_count,
  @socs_failures AS postcheck_failure_count;

SET @socs_finish_sql := IF(
  @socs_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_SALES_ORDER_CRAFTSMEN_SNAPSHOT_POSTCHECK_FAILED'
);
PREPARE socs_finish_stmt FROM @socs_finish_sql;
EXECUTE socs_finish_stmt;
DEALLOCATE PREPARE socs_finish_stmt;
