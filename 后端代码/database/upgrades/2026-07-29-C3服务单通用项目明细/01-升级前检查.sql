-- upgrade_key: 20260729-006-c3-generic-service-order-line
-- Read-only. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @c3gl_db := DATABASE();
SET @c3gl_failures := 0;

SELECT COUNT(*) INTO @c3gl_base_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c3gl_db
  AND TABLE_NAME='eb_cashier_v3_service_order_line'
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @c3gl_existing_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c3gl_db
  AND TABLE_NAME='eb_cashier_v3_service_order_line'
  AND COLUMN_NAME IN (
    'source_type','source_id','source_version_snapshot','hang_line_id',
    'service_quantity','authority_fingerprint'
  );

SET @c3gl_failures := @c3gl_failures
  + IF(@c3gl_base_table=1,0,1)
  + IF(@c3gl_existing_columns IN (0,6),0,1);
SELECT @c3gl_base_table AS base_table_count,
  @c3gl_existing_columns AS existing_column_count,
  @c3gl_failures AS precheck_failure_count;
SET @c3gl_finish_sql := IF(
  @c3gl_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_C3_GENERIC_LINE_PRECHECK_FAILED'
);
PREPARE c3gl_finish_stmt FROM @c3gl_finish_sql;
EXECUTE c3gl_finish_stmt;
DEALLOCATE PREPARE c3gl_finish_stmt;

