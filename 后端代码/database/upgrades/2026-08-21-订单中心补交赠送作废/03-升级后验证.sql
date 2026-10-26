-- upgrade_key: 20260821-005-cashier-v3-order-center-void
SET NAMES utf8mb4;
SET @ocv_db := DATABASE();
SELECT COUNT(*) AS target_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ocv_db
  AND TABLE_NAME='eb_cashier_v3_order_center_void_operation'
  AND ENGINE='InnoDB';
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ocv_db
  AND TABLE_NAME='eb_cashier_v3_order_center_void_operation'
  AND COLUMN_NAME IN ('operation_id','record_id','business_event_no','command_idempotency_key','status')
ORDER BY ORDINAL_POSITION;
SELECT COUNT(*) AS unique_key_count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@ocv_db
  AND TABLE_NAME='eb_cashier_v3_order_center_void_operation'
  AND NON_UNIQUE=0
  AND INDEX_NAME IN ('uk_order_center_void_operation','uk_order_center_void_no','uk_order_center_void_record','uk_order_center_void_command');
SELECT COUNT(*) AS upgrade_log_count
FROM eb_database_upgrade_log
WHERE upgrade_key='20260821-005-cashier-v3-order-center-void';
SELECT 'POSTCHECK_OK' AS postcheck_result;
