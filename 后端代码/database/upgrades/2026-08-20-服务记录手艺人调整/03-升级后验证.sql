SET NAMES utf8mb4;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_performance_fact'
  AND COLUMN_NAME = 'project_count_half_units';

SELECT COUNT(*) AS adjustment_operation_table_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_service_record_adjustment_operation';

SELECT upgrade_key, title, executed_at, result_note
FROM eb_database_upgrade_log
WHERE upgrade_key='20260820-002-service-record-craftsman-adjustment';
