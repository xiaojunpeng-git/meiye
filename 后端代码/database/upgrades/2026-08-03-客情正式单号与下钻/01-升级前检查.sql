-- upgrade_key: 20260803-001-customer-care-document-number
-- Read-only precheck. Do not run 02 unless this ends in PRECHECK_OK.
SET NAMES utf8mb4;
SET @care_db := DATABASE();
SET @care_key := '20260803-001-customer-care-document-number';
SET @care_failures := 0;

SELECT COUNT(*) INTO @care_core_registered
FROM eb_database_upgrade_log
WHERE upgrade_key='20260729-001-customer-care-core';
SELECT COUNT(*) INTO @care_new_registered
FROM eb_database_upgrade_log
WHERE upgrade_key=@care_key;
SELECT COUNT(*) INTO @care_core_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME IN ('eb_customer_care_task','eb_customer_care_record')
  AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @care_existing_document_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND ((TABLE_NAME='eb_customer_care_task' AND COLUMN_NAME='task_no')
    OR (TABLE_NAME='eb_customer_care_record' AND COLUMN_NAME='record_no'));
SELECT COUNT(*) INTO @care_existing_sequence_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_customer_care_document_sequence';

SET @care_failures := @care_failures
  + IF(@care_core_registered=1,0,1)
  + IF(@care_new_registered=0,0,1)
  + IF(@care_core_tables=2,0,1)
  + IF(@care_existing_document_columns=0,0,1)
  + IF(@care_existing_sequence_table=0,0,1);

SELECT @care_core_registered AS core_registered,
       @care_new_registered AS target_registered,
       @care_core_tables AS core_table_count,
       @care_existing_document_columns AS existing_document_column_count,
       @care_existing_sequence_table AS existing_sequence_table_count,
       @care_failures AS precheck_failure_count;
SET @care_finish_sql := IF(@care_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CUSTOMER_CARE_DOCUMENT_NUMBER_PRECHECK_FAILED');
PREPARE care_finish_stmt FROM @care_finish_sql;
EXECUTE care_finish_stmt;
DEALLOCATE PREPARE care_finish_stmt;

