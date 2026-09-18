-- upgrade_key: 20260918-001-cashier-v3-project-count-decimal
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT COUNT(*) AS project_count_decimal_column_ready
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_performance_fact'
  AND COLUMN_NAME='project_count_decimal'
  AND COLUMN_TYPE='decimal(20,6)'
  AND IS_NULLABLE='YES';

SELECT CASE WHEN COUNT(*) = 1 THEN 'UPGRADE_LOG_OK' ELSE 'UPGRADE_LOG_FAILURE' END AS upgrade_log_result
FROM `eb_database_upgrade_log`
WHERE `upgrade_key`='20260918-001-cashier-v3-project-count-decimal';
