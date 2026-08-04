-- upgrade_key: 20260804-002-cashier-v3-sales-order-craftsmen-snapshot-v1
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @socs_db := DATABASE();
SET @socs_failures := 0;

SELECT COUNT(*) INTO @socs_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@socs_db AND TABLE_NAME='eb_database_upgrade_log';

SET @socs_registered := 0;
SET @socs_registered_sql := IF(
  @socs_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @socs_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260804-002-cashier-v3-sales-order-craftsmen-snapshot-v1''',
  'SELECT 0 INTO @socs_registered'
);
PREPARE socs_registered_stmt FROM @socs_registered_sql;
EXECUTE socs_registered_stmt;
DEALLOCATE PREPARE socs_registered_stmt;

SELECT COUNT(*) INTO @socs_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@socs_db
  AND TABLE_NAME IN ('eb_cashier_v3_checkout_line_draft','eb_cashier_v3_sales_order_line')
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @socs_existing_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@socs_db
  AND TABLE_NAME IN ('eb_cashier_v3_checkout_line_draft','eb_cashier_v3_sales_order_line')
  AND COLUMN_NAME='craftsmen_snapshot_json';

SET @socs_failures := @socs_failures
  + IF(@socs_upgrade_log_exists=1,0,1)
  + IF(@socs_registered=0,0,1)
  + IF(@socs_target_tables=2,0,1)
  + IF(@socs_existing_columns=0,0,1);

SELECT @socs_db AS db_name, @socs_registered AS already_registered,
  @socs_target_tables AS target_table_count,
  @socs_existing_columns AS existing_snapshot_column_count,
  @socs_failures AS precheck_failure_count;

SET @socs_finish_sql := IF(
  @socs_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_SALES_ORDER_CRAFTSMEN_SNAPSHOT_PRECHECK_FAILED'
);
PREPARE socs_finish_stmt FROM @socs_finish_sql;
EXECUTE socs_finish_stmt;
DEALLOCATE PREPARE socs_finish_stmt;
