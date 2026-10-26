-- upgrade_key: 20260729-001-customer-care-core
-- Read-only precheck. Fresh core tables must all be absent.
SET NAMES utf8mb4;
SET @care_db := DATABASE();
SET @care_failures := 0;
SET @care_c1_upgrade_key_used := 0;
SET @care_employee_upgrade_key_used := 0;

SELECT @care_db AS db_name, VERSION() AS mysql_version;

SELECT COUNT(*) INTO @care_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @care_upgrade_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key'
  AND COLUMN_TYPE='varchar(100)'
  AND IS_NULLABLE='NO';

SET @care_upgrade_key_used := 0;
SET @care_log_sql := IF(
  @care_upgrade_log_exists=1 AND @care_upgrade_key_column=1,
  'SELECT COALESCE(SUM(upgrade_key=''20260729-001-customer-care-core''),0),COALESCE(SUM(upgrade_key=''20260727-001-cashier-v3-command-idem''),0),COALESCE(SUM(upgrade_key=''20260719-009-employee-org-leader''),0) INTO @care_upgrade_key_used,@care_c1_upgrade_key_used,@care_employee_upgrade_key_used FROM eb_database_upgrade_log WHERE upgrade_key IN (''20260729-001-customer-care-core'',''20260727-001-cashier-v3-command-idem'',''20260719-009-employee-org-leader'')',
  'SET @care_upgrade_key_used:=0,@care_c1_upgrade_key_used:=0,@care_employee_upgrade_key_used:=0'
);
PREPARE care_log_stmt FROM @care_log_sql;
EXECUTE care_log_stmt;
DEALLOCATE PREPARE care_log_stmt;

SELECT COUNT(*) INTO @care_target_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME IN (
  'eb_customer_care_task',
  'eb_customer_care_record',
  'eb_customer_care_operation'
);

SELECT COUNT(*) INTO @care_c1_receipt_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME='eb_cashier_v3_command_receipt'
  AND ENGINE='InnoDB'
  AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @care_c1_receipt_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_cashier_v3_command_receipt';

SELECT COUNT(*) INTO @care_c1_receipt_ascii_contract_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME='eb_cashier_v3_command_receipt'
  AND CHARACTER_SET_NAME='ascii'
  AND COLLATION_NAME='ascii_bin'
  AND COLUMN_NAME IN (
    'idempotency_key','action','state_context_id','request_hash','contexts_hash',
    'result_code','business_no','operator_ip'
  );

SELECT COUNT(*) INTO @care_c1_receipt_payload_contract_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME='eb_cashier_v3_command_receipt'
  AND COLUMN_NAME IN ('contexts_json','result_json')
  AND COLUMN_TYPE='mediumtext'
  AND IS_NULLABLE='YES';

SELECT COUNT(*) INTO @care_c1_receipt_unique_contract_count
FROM (
  SELECT INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
         GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_cashier_v3_command_receipt'
  GROUP BY INDEX_NAME
  HAVING non_unique=0 AND index_columns='idempotency_key'
) care_c1_receipt_unique;

SELECT COUNT(*) INTO @care_employee_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME IN ('eb_employee','eb_system_store_staff')
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @care_employee_column_contract_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND (
    (TABLE_NAME='eb_employee' AND COLUMN_NAME IN ('id','status','is_del'))
    OR (TABLE_NAME='eb_system_store_staff' AND COLUMN_NAME IN (
      'id','store_id','employee_id','staff_name','status','is_del'
    ))
  );

SELECT COUNT(*) INTO @care_employee_id_column_contract_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND (
    (TABLE_NAME='eb_employee' AND COLUMN_NAME='id'
      AND COLUMN_TYPE='int(10) unsigned' AND IS_NULLABLE='NO')
    OR (TABLE_NAME='eb_system_store_staff' AND COLUMN_NAME='employee_id'
      AND COLUMN_TYPE='int(10) unsigned' AND IS_NULLABLE='YES')
  );

SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,'|',INDEX_NAME))
INTO @care_employee_index_contract_count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@care_db
  AND (
    (TABLE_NAME='eb_employee' AND INDEX_NAME='idx_employee_status_del')
    OR (TABLE_NAME='eb_system_store_staff' AND INDEX_NAME IN (
      'idx_staff_employee_store','idx_staff_store_employee'
    ))
  );

SET @care_invalid_active_assignment_count := -1;
SET @care_assignment_sql := IF(
  @care_employee_table_count=2 AND @care_employee_column_contract_count=9,
  'SELECT COUNT(*) INTO @care_invalid_active_assignment_count FROM eb_system_store_staff staff LEFT JOIN eb_employee employee ON employee.id=staff.employee_id WHERE staff.status=1 AND staff.is_del=0 AND (staff.employee_id IS NULL OR staff.employee_id=0 OR employee.id IS NULL OR employee.status<>1 OR employee.is_del<>0)',
  'SET @care_invalid_active_assignment_count:=-1'
);
PREPARE care_assignment_stmt FROM @care_assignment_sql;
EXECUTE care_assignment_stmt;
DEALLOCATE PREPARE care_assignment_stmt;

SET @care_failures := @care_failures
  + IF(@care_upgrade_log_exists=1,0,1)
  + IF(@care_upgrade_key_column=1,0,1)
  + IF(@care_upgrade_key_used=0,0,1)
  + IF(@care_c1_upgrade_key_used=1,0,1)
  + IF(@care_employee_upgrade_key_used=1,0,1)
  + IF(@care_c1_receipt_table_count=1,0,1)
  + IF(@care_c1_receipt_column_count=17,0,1)
  + IF(@care_c1_receipt_ascii_contract_count=8,0,1)
  + IF(@care_c1_receipt_payload_contract_count=2,0,1)
  + IF(@care_c1_receipt_unique_contract_count=1,0,1)
  + IF(@care_employee_table_count=2,0,1)
  + IF(@care_employee_column_contract_count=9,0,1)
  + IF(@care_employee_id_column_contract_count=2,0,1)
  + IF(@care_employee_index_contract_count=3,0,1)
  + IF(@care_invalid_active_assignment_count=0,0,1)
  + IF(@care_target_table_count=0,0,1);

SELECT
  @care_upgrade_log_exists AS upgrade_log_exists,
  @care_upgrade_key_column AS upgrade_key_column_exact,
  @care_upgrade_key_used AS upgrade_key_used,
  @care_c1_upgrade_key_used AS c1_dependency_registered,
  @care_employee_upgrade_key_used AS employee_dependency_registered,
  @care_c1_receipt_table_count AS c1_receipt_table_exact,
  @care_c1_receipt_column_count AS c1_receipt_column_count,
  @care_c1_receipt_ascii_contract_count AS c1_receipt_ascii_contract_count,
  @care_c1_receipt_payload_contract_count AS c1_receipt_payload_contract_count,
  @care_c1_receipt_unique_contract_count AS c1_receipt_unique_contract_count,
  @care_employee_table_count AS employee_table_count,
  @care_employee_column_contract_count AS employee_column_contract_count,
  @care_employee_id_column_contract_count AS employee_id_column_contract_count,
  @care_employee_index_contract_count AS employee_index_contract_count,
  @care_invalid_active_assignment_count AS invalid_active_assignment_count,
  @care_target_table_count AS target_table_count,
  @care_failures AS precheck_failure_count;

SET @care_finish_sql := IF(
  @care_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CUSTOMER_CARE_PRECHECK_FAILED'
);
PREPARE care_finish_stmt FROM @care_finish_sql;
EXECUTE care_finish_stmt;
DEALLOCATE PREPARE care_finish_stmt;
