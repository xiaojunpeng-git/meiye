-- upgrade_key: 20260830-001-customer-care-service-photos
SET NAMES utf8mb4;
SET @care_db := DATABASE();
SET @care_failures := 0;
SELECT COUNT(*) INTO @care_core_registered FROM eb_database_upgrade_log WHERE upgrade_key='20260729-001-customer-care-core';
SELECT COUNT(*) INTO @care_existing_columns FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_customer_care_record'
 AND COLUMN_NAME IN ('service_before_photos','service_after_photos');
SELECT COUNT(*) INTO @care_table FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_customer_care_record' AND ENGINE='InnoDB';
SET @care_failures := @care_failures + IF(@care_core_registered=1,0,1) + IF(@care_table=1,0,1);
SELECT @care_core_registered AS core_registered, @care_existing_columns AS existing_photo_columns,
       @care_table AS record_table, @care_failures AS precheck_failure_count;
SET @care_finish_sql := IF(@care_failures=0, 'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CUSTOMER_CARE_SERVICE_PHOTOS_PRECHECK_FAILED');
PREPARE care_finish_stmt FROM @care_finish_sql; EXECUTE care_finish_stmt; DEALLOCATE PREPARE care_finish_stmt;
