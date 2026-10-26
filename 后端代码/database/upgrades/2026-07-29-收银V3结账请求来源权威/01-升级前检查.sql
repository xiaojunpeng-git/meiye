-- upgrade_key: 20260729-006-cashier-v3-checkout-source-authority
-- Read-only MySQL 5.6 precheck. Any existing target table blocks normal apply.
SET NAMES utf8mb4;
SET @checkout_source_db := DATABASE();
SET @checkout_source_failures := 0;

SELECT COUNT(*) INTO @checkout_source_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_source_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @checkout_source_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_source_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';

SET @checkout_source_key_used := 0;
SET @checkout_source_log_sql := IF(
  @checkout_source_log_exists=1 AND @checkout_source_key_column=1,
  'SELECT COUNT(*) INTO @checkout_source_key_used FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-006-cashier-v3-checkout-source-authority''',
  'SET @checkout_source_key_used:=0'
);
PREPARE checkout_source_log_stmt FROM @checkout_source_log_sql;
EXECUTE checkout_source_log_stmt;
DEALLOCATE PREPARE checkout_source_log_stmt;

SELECT COUNT(*) INTO @checkout_source_request_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_source_db
  AND TABLE_NAME='eb_cashier_v3_checkout_request'
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @checkout_source_request_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_source_db
  AND TABLE_NAME='eb_cashier_v3_checkout_request'
  AND COLUMN_NAME IN ('request_id','tenant_id','store_id','request_version');

SELECT COUNT(*) INTO @checkout_source_target_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_source_db
  AND TABLE_NAME='eb_cashier_v3_checkout_source_reference';

SET @checkout_source_failures := @checkout_source_failures
  + IF(@checkout_source_log_exists=1,0,1)
  + IF(@checkout_source_key_column=1,0,1)
  + IF(@checkout_source_key_used=0,0,1)
  + IF(@checkout_source_request_table=1,0,1)
  + IF(@checkout_source_request_columns=4,0,1)
  + IF(@checkout_source_target_count=0,0,1);

SELECT
  @checkout_source_db AS db_name,
  VERSION() AS mysql_version,
  @checkout_source_key_used AS upgrade_key_used,
  @checkout_source_request_table AS request_table_ready,
  @checkout_source_request_columns AS request_authority_column_count,
  @checkout_source_target_count AS target_table_count,
  @checkout_source_failures AS precheck_failure_count;

SET @checkout_source_finish_sql := IF(
  @checkout_source_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''checkout source authority precheck failed; run partial DDL audit'''
);
PREPARE checkout_source_finish_stmt FROM @checkout_source_finish_sql;
EXECUTE checkout_source_finish_stmt;
DEALLOCATE PREPARE checkout_source_finish_stmt;
