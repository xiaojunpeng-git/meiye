-- upgrade_key: 20260830-001-customer-care-service-photos
SET NAMES utf8mb4;
SET @care_db := DATABASE();
SET @care_failures := 0;
SELECT COUNT(*) INTO @care_columns FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_customer_care_record'
 AND COLUMN_NAME IN ('service_before_photos','service_after_photos')
 AND DATA_TYPE='text' AND IS_NULLABLE='NO';
SELECT COUNT(*) INTO @care_bad_rows FROM eb_customer_care_record
 WHERE service_before_photos IS NULL OR service_after_photos IS NULL;
SET @care_failures := @care_failures + IF(@care_columns=2,0,1) + IF(@care_bad_rows=0,0,1);
SELECT @care_columns AS photo_column_count, @care_bad_rows AS bad_rows,
       @care_failures AS postcheck_failure_count;
SET @care_finish_sql := IF(@care_failures=0, 'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CUSTOMER_CARE_SERVICE_PHOTOS_POSTCHECK_FAILED');
PREPARE care_finish_stmt FROM @care_finish_sql; EXECUTE care_finish_stmt; DEALLOCATE PREPARE care_finish_stmt;
