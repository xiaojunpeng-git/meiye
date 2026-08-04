-- upgrade_key: 20260802-001-cashier-v3-sales-order-service-tags-v1
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @sot_db := DATABASE();
SET @sot_failures := 0;

SELECT COUNT(*) INTO @sot_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@sot_db AND TABLE_NAME='eb_database_upgrade_log';

SET @sot_registered := 0;
SET @sot_registered_sql := IF(
  @sot_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @sot_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260802-001-cashier-v3-sales-order-service-tags-v1''',
  'SELECT 0 INTO @sot_registered'
);
PREPARE sot_registered_stmt FROM @sot_registered_sql;
EXECUTE sot_registered_stmt;
DEALLOCATE PREPARE sot_registered_stmt;

SELECT COUNT(*) INTO @sot_target_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@sot_db AND TABLE_NAME='eb_cashier_v3_sales_order_line' AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @sot_existing_tag_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@sot_db AND TABLE_NAME='eb_cashier_v3_sales_order_line'
  AND COLUMN_NAME IN ('service_object','is_experience');

SET @sot_failures := @sot_failures
  + IF(@sot_upgrade_log_exists=1,0,1)
  + IF(@sot_registered=0,0,1)
  + IF(@sot_target_table=1,0,1)
  + IF(@sot_existing_tag_columns=0,0,1);

SELECT @sot_db AS db_name, @sot_registered AS already_registered,
  @sot_target_table AS target_table_count,
  @sot_existing_tag_columns AS existing_service_tag_column_count,
  @sot_failures AS precheck_failure_count;

SET @sot_finish_sql := IF(
  @sot_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_SALES_ORDER_SERVICE_TAGS_PRECHECK_FAILED'
);
PREPARE sot_finish_stmt FROM @sot_finish_sql;
EXECUTE sot_finish_stmt;
DEALLOCATE PREPARE sot_finish_stmt;
