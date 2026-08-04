-- upgrade_key: 20260729-011-cashier-v3-sales-order-authority-v1
-- Read-only interrupted two-table DDL audit. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @so_db := DATABASE();

SELECT COUNT(*) INTO @so_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@so_db AND TABLE_NAME='eb_database_upgrade_log';
SET @so_registered := 0;
SET @so_registered_sql := IF(
  @so_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @so_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-011-cashier-v3-sales-order-authority-v1''',
  'SELECT 0 INTO @so_registered'
);
PREPARE so_registered_stmt FROM @so_registered_sql;
EXECUTE so_registered_stmt;
DEALLOCATE PREPARE so_registered_stmt;

SELECT COUNT(*) INTO @so_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@so_db
  AND TABLE_NAME IN ('eb_cashier_v3_sales_order','eb_cashier_v3_sales_order_line');

SELECT COUNT(*) INTO @so_exact_tables FROM (
  SELECT target.table_name
  FROM (
    SELECT 'eb_cashier_v3_sales_order' AS table_name,44 AS column_count,11 AS index_count
    UNION ALL SELECT 'eb_cashier_v3_sales_order_line',32,9
  ) target
  INNER JOIN information_schema.TABLES table_meta
    ON table_meta.TABLE_SCHEMA=@so_db AND table_meta.TABLE_NAME=target.table_name
   AND table_meta.ENGINE='InnoDB' AND table_meta.TABLE_COLLATION='utf8mb4_general_ci'
  INNER JOIN (
    SELECT TABLE_NAME,COUNT(*) AS column_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=@so_db
      AND TABLE_NAME IN ('eb_cashier_v3_sales_order','eb_cashier_v3_sales_order_line')
    GROUP BY TABLE_NAME
  ) column_meta
    ON column_meta.TABLE_NAME=target.table_name AND column_meta.column_count=target.column_count
  INNER JOIN (
    SELECT TABLE_NAME,COUNT(*) AS index_count
    FROM (
      SELECT TABLE_NAME,INDEX_NAME
      FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=@so_db
        AND TABLE_NAME IN ('eb_cashier_v3_sales_order','eb_cashier_v3_sales_order_line')
      GROUP BY TABLE_NAME,INDEX_NAME
    ) index_names
    GROUP BY TABLE_NAME
  ) index_meta
    ON index_meta.TABLE_NAME=target.table_name AND index_meta.index_count=target.index_count
) exact_targets;

SET @so_header_rows := 0;
SET @so_line_rows := 0;
SET @so_header_count_sql := IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@so_db AND TABLE_NAME='eb_cashier_v3_sales_order'),
  'SELECT COUNT(*) INTO @so_header_rows FROM eb_cashier_v3_sales_order',
  'SELECT 0 INTO @so_header_rows'
);
PREPARE so_header_count_stmt FROM @so_header_count_sql;
EXECUTE so_header_count_stmt;
DEALLOCATE PREPARE so_header_count_stmt;
SET @so_line_count_sql := IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@so_db AND TABLE_NAME='eb_cashier_v3_sales_order_line'),
  'SELECT COUNT(*) INTO @so_line_rows FROM eb_cashier_v3_sales_order_line',
  'SELECT 0 INTO @so_line_rows'
);
PREPARE so_line_count_stmt FROM @so_line_count_sql;
EXECUTE so_line_count_stmt;
DEALLOCATE PREPARE so_line_count_stmt;

SET @so_partial_state := (@so_target_tables=1);
SET @so_recovery_ready := @so_partial_state=1
  AND @so_upgrade_log_exists=1
  AND @so_registered=0
  AND @so_exact_tables=@so_target_tables
  AND (@so_header_rows+@so_line_rows)=0;

SELECT
  @so_target_tables AS existing_target_table_count,
  @so_exact_tables AS exact_existing_table_count,
  @so_header_rows AS existing_header_row_count,
  @so_line_rows AS existing_line_row_count,
  @so_registered AS upgrade_registered,
  @so_recovery_ready AS partial_recovery_ready;

SET @so_finish_sql := CASE
  WHEN @so_target_tables=0 THEN 'SELECT * FROM STOP_SALES_ORDER_PARTIAL_FRESH_STATE'
  WHEN @so_target_tables=2 THEN 'SELECT * FROM STOP_SALES_ORDER_PARTIAL_COMPLETE_STATE'
  WHEN @so_upgrade_log_exists<>1 THEN 'SELECT * FROM STOP_SALES_ORDER_PARTIAL_UPGRADE_LOG_MISSING'
  WHEN @so_registered<>0 THEN 'SELECT * FROM STOP_SALES_ORDER_PARTIAL_ALREADY_REGISTERED'
  WHEN @so_exact_tables<>@so_target_tables THEN 'SELECT * FROM STOP_SALES_ORDER_PARTIAL_HETEROGENEOUS_SCHEMA'
  WHEN (@so_header_rows+@so_line_rows)<>0 THEN 'SELECT * FROM STOP_SALES_ORDER_PARTIAL_ROWS_EXIST'
  ELSE 'SELECT ''PARTIAL_CREATE_RECOVERY_READY'' AS recovery_result'
END;
PREPARE so_finish_stmt FROM @so_finish_sql;
EXECUTE so_finish_stmt;
DEALLOCATE PREPARE so_finish_stmt;
