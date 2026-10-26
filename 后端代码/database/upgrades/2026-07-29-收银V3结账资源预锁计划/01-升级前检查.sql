-- upgrade_key: 20260729-010-cashier-v3-checkout-resource-plan
-- Read-only MySQL 5.6 precheck. Existing target objects block normal apply.
SET NAMES utf8mb4;
SET @checkout_plan_db := DATABASE();
SET @checkout_plan_failures := 0;

SELECT COUNT(*) INTO @checkout_plan_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_plan_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @checkout_plan_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_plan_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';

SET @checkout_plan_key_used := 0;
SET @checkout_plan_log_sql := IF(
  @checkout_plan_log_exists=1 AND @checkout_plan_key_column=1,
  'SELECT COUNT(*) INTO @checkout_plan_key_used FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-010-cashier-v3-checkout-resource-plan''',
  'SET @checkout_plan_key_used:=0'
);
PREPARE checkout_plan_log_stmt FROM @checkout_plan_log_sql;
EXECUTE checkout_plan_log_stmt;
DEALLOCATE PREPARE checkout_plan_log_stmt;

SELECT COUNT(*) INTO @checkout_plan_request_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_plan_db
  AND TABLE_NAME='eb_cashier_v3_checkout_request'
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @checkout_plan_request_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_plan_db
  AND TABLE_NAME='eb_cashier_v3_checkout_request'
  AND COLUMN_NAME IN ('request_id','tenant_id','store_id','request_version','request_status');

SELECT COUNT(*) INTO @checkout_plan_target_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_plan_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_resource_plan',
    'eb_cashier_v3_checkout_resource_plan_row'
  );

SET @checkout_plan_failures := @checkout_plan_failures
  + IF(@checkout_plan_log_exists=1,0,1)
  + IF(@checkout_plan_key_column=1,0,1)
  + IF(@checkout_plan_key_used=0,0,1)
  + IF(@checkout_plan_request_table=1,0,1)
  + IF(@checkout_plan_request_columns=5,0,1)
  + IF(@checkout_plan_target_count=0,0,1);

SELECT
  @checkout_plan_db AS db_name,
  VERSION() AS mysql_version,
  @checkout_plan_key_used AS upgrade_key_used,
  @checkout_plan_request_table AS request_table_ready,
  @checkout_plan_request_columns AS request_authority_column_count,
  @checkout_plan_target_count AS target_table_count,
  @checkout_plan_failures AS precheck_failure_count;

SET @checkout_plan_finish_sql := IF(
  @checkout_plan_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''checkout resource plan precheck failed; run partial DDL audit'''
);
PREPARE checkout_plan_finish_stmt FROM @checkout_plan_finish_sql;
EXECUTE checkout_plan_finish_stmt;
DEALLOCATE PREPARE checkout_plan_finish_stmt;
