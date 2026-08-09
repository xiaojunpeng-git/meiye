-- upgrade_key: 20260729-005-c3-service-order-authority
-- Read-only, non-destructive partial-DDL validator.
SET NAMES utf8mb4;
SET @c3so_db := DATABASE();
SET @c3so_failures := 0;

SELECT COUNT(*) INTO @c3so_existing_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME IN (
  'eb_cashier_v3_service_order','eb_cashier_v3_service_order_line',
  'eb_cashier_v3_service_order_entitlement_guard','eb_cashier_v3_service_order_operation'
);
SELECT COUNT(*) INTO @c3so_shape_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME IN (
  'eb_cashier_v3_service_order','eb_cashier_v3_service_order_line',
  'eb_cashier_v3_service_order_entitlement_guard','eb_cashier_v3_service_order_operation'
) AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SET @c3so_rows := 0;
SET @c3so_row_sql := CONCAT(
  'SELECT ',
  IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME='eb_cashier_v3_service_order'),
    '(SELECT COUNT(*) FROM eb_cashier_v3_service_order)+','0+'),
  IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME='eb_cashier_v3_service_order_line'),
    '(SELECT COUNT(*) FROM eb_cashier_v3_service_order_line)+','0+'),
  IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME='eb_cashier_v3_service_order_entitlement_guard'),
    '(SELECT COUNT(*) FROM eb_cashier_v3_service_order_entitlement_guard)+','0+'),
  IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME='eb_cashier_v3_service_order_operation'),
    '(SELECT COUNT(*) FROM eb_cashier_v3_service_order_operation)','0'),
  ' INTO @c3so_rows'
);
PREPARE c3so_row_stmt FROM @c3so_row_sql;
EXECUTE c3so_row_stmt;
DEALLOCATE PREPARE c3so_row_stmt;

SELECT COUNT(*) INTO @c3so_signature_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c3so_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_cashier_v3_service_order.service_order_no','eb_cashier_v3_service_order.version',
  'eb_cashier_v3_service_order_line.line_key','eb_cashier_v3_service_order_line.entitlement_source_detail_id',
  'eb_cashier_v3_service_order_entitlement_guard.entitlement_source_detail_id',
  'eb_cashier_v3_service_order_entitlement_guard.current_version',
  'eb_cashier_v3_service_order_operation.command_idempotency_key',
  'eb_cashier_v3_service_order_operation.request_fingerprint'
);

SET @c3so_expected_signatures :=
  IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME='eb_cashier_v3_service_order'),2,0)
  + IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME='eb_cashier_v3_service_order_line'),2,0)
  + IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME='eb_cashier_v3_service_order_entitlement_guard'),2,0)
  + IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME='eb_cashier_v3_service_order_operation'),2,0);
SET @c3so_failures := @c3so_failures
  + IF(@c3so_existing_count BETWEEN 1 AND 3,0,1)
  + IF(@c3so_shape_count=@c3so_existing_count,0,1)
  + IF(@c3so_rows=0,0,1)
  + IF(@c3so_signature_count=@c3so_expected_signatures,0,1);

SELECT @c3so_existing_count AS existing_table_count,@c3so_rows AS existing_row_count,
  @c3so_signature_count AS signature_count,@c3so_failures AS recovery_failure_count;
SET @c3so_finish_sql := IF(
  @c3so_failures=0,
  'SELECT ''PARTIAL_DDL_RECOVERY_READY'' AS recovery_result',
  'SELECT * FROM STOP_C3_SERVICE_ORDER_PARTIAL_RECOVERY_FAILED'
);
PREPARE c3so_finish_stmt FROM @c3so_finish_sql;
EXECUTE c3so_finish_stmt;
DEALLOCATE PREPARE c3so_finish_stmt;
