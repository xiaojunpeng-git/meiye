-- upgrade_key: 20260803-001-customer-care-document-number
SET NAMES utf8mb4;
SET @care_db := DATABASE();
SET @care_failures := 0;

SELECT COUNT(*) INTO @care_task_no_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_customer_care_task'
  AND COLUMN_NAME='task_no' AND COLUMN_TYPE='varchar(16)'
  AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='YES'
  AND COLUMN_DEFAULT IS NULL;
SELECT COUNT(*) INTO @care_record_no_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_customer_care_record'
  AND COLUMN_NAME='record_no' AND COLUMN_TYPE='varchar(16)'
  AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='YES'
  AND COLUMN_DEFAULT IS NULL;
SELECT COUNT(*) INTO @care_sequence_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_customer_care_document_sequence'
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';
SELECT COUNT(*) INTO @care_unique_numbers
FROM (
  SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_text
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME IN ('eb_customer_care_task','eb_customer_care_record')
    AND NON_UNIQUE=0
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING (TABLE_NAME='eb_customer_care_task' AND columns_text='tenant_id,task_no')
      OR (TABLE_NAME='eb_customer_care_record' AND columns_text='tenant_id,record_no')
) number_indexes;
SELECT COUNT(*) INTO @care_bad_task_no
FROM eb_customer_care_task
WHERE task_no IS NOT NULL AND (task_no='' OR CHAR_LENGTH(task_no)<>12 OR LEFT(task_no,2)<>'GJ'
  OR SUBSTRING(task_no,3) REGEXP '[^0-9]');
SELECT COUNT(*) INTO @care_bad_record_no
FROM eb_customer_care_record
WHERE record_no IS NOT NULL AND (record_no='' OR CHAR_LENGTH(record_no)<>12 OR LEFT(record_no,2)<>'KQ'
  OR SUBSTRING(record_no,3) REGEXP '[^0-9]');
SELECT COUNT(*) INTO @care_task_duplicate_no
FROM (SELECT tenant_id,task_no,COUNT(*) AS amount FROM eb_customer_care_task WHERE task_no IS NOT NULL GROUP BY tenant_id,task_no HAVING amount>1) duplicate_task_no;
SELECT COUNT(*) INTO @care_record_duplicate_no
FROM (SELECT tenant_id,record_no,COUNT(*) AS amount FROM eb_customer_care_record WHERE record_no IS NOT NULL GROUP BY tenant_id,record_no HAVING amount>1) duplicate_record_no;
SELECT COUNT(*) INTO @care_sequence_invalid
FROM eb_customer_care_document_sequence
WHERE tenant_id='' OR business_date NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
  OR document_type NOT IN ('TASK','RECORD') OR current_value<1 OR current_value>9999;

SET @care_failures := @care_failures
  + IF(@care_task_no_column=1,0,1)
  + IF(@care_record_no_column=1,0,1)
  + IF(@care_sequence_table=1,0,1)
  + IF(@care_unique_numbers=2,0,1)
  + IF(@care_bad_task_no=0,0,1)
  + IF(@care_bad_record_no=0,0,1)
  + IF(@care_task_duplicate_no=0,0,1)
  + IF(@care_record_duplicate_no=0,0,1)
  + IF(@care_sequence_invalid=0,0,1);
SELECT @care_task_no_column AS task_no_column,
       @care_record_no_column AS record_no_column,
       @care_sequence_table AS sequence_table,
       @care_unique_numbers AS unique_number_indexes,
       @care_bad_task_no AS bad_task_numbers,
       @care_bad_record_no AS bad_record_numbers,
       @care_task_duplicate_no AS duplicate_task_numbers,
       @care_record_duplicate_no AS duplicate_record_numbers,
       @care_sequence_invalid AS invalid_sequence_rows,
       @care_failures AS postcheck_failure_count;
SET @care_finish_sql := IF(@care_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CUSTOMER_CARE_DOCUMENT_NUMBER_POSTCHECK_FAILED');
PREPARE care_finish_stmt FROM @care_finish_sql;
EXECUTE care_finish_stmt;
DEALLOCATE PREPARE care_finish_stmt;
