-- upgrade_key: 20260729-005-c3-service-order-authority
-- Read only. Partial target DDL is rejected and must pass 05 first.
SET NAMES utf8mb4;
SET @c3so_db := DATABASE();
SET @c3so_failures := 0;

SELECT COUNT(*) INTO @c3so_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @c3so_target_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME IN (
  'eb_cashier_v3_service_order',
  'eb_cashier_v3_service_order_line',
  'eb_cashier_v3_service_order_entitlement_guard',
  'eb_cashier_v3_service_order_operation'
);

SET @c3so_registered := 0;
SET @c3so_registered_sql := IF(
  @c3so_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @c3so_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-005-c3-service-order-authority''',
  'SELECT 0 INTO @c3so_registered'
);
PREPARE c3so_registered_stmt FROM @c3so_registered_sql;
EXECUTE c3so_registered_stmt;
DEALLOCATE PREPARE c3so_registered_stmt;

SET @c3so_failures := @c3so_failures
  + IF(@c3so_upgrade_log_exists=1,0,1)
  + IF(@c3so_target_count IN (0,4),0,1)
  + IF(@c3so_registered IN (0,1),0,1);

SELECT @c3so_db AS db_name,VERSION() AS mysql_version,
  @c3so_target_count AS target_table_count,
  @c3so_registered AS already_registered,
  @c3so_failures AS precheck_failure_count;

SET @c3so_finish_sql := IF(
  @c3so_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_C3_SERVICE_ORDER_PRECHECK_FAILED'
);
PREPARE c3so_finish_stmt FROM @c3so_finish_sql;
EXECUTE c3so_finish_stmt;
DEALLOCATE PREPARE c3so_finish_stmt;
