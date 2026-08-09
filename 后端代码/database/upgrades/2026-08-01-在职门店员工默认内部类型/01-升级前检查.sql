-- upgrade_key: 20260801-002-active-store-employee-default-internal
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @asidei_db := DATABASE();
SET @asidei_failures := 0;

SELECT COUNT(*) INTO @asidei_required_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@asidei_db
  AND TABLE_NAME IN (
    'eb_database_upgrade_log',
    'eb_employee',
    'eb_system_store_staff',
    'eb_employee_change_log'
  )
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @asidei_required_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@asidei_db
  AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
    'eb_employee.id','eb_employee.status','eb_employee.is_del',
    'eb_employee.employment_type_code','eb_employee.employment_type_version',
    'eb_system_store_staff.employee_id','eb_system_store_staff.status',
    'eb_system_store_staff.is_del','eb_system_store_staff.is_hezuofang',
    'eb_system_store_staff.is_fencheng',
    'eb_employee_change_log.employee_id','eb_employee_change_log.action',
    'eb_employee_change_log.target_type','eb_employee_change_log.target_id',
    'eb_employee_change_log.source','eb_employee_change_log.before_data',
    'eb_employee_change_log.after_data','eb_employee_change_log.reason',
    'eb_employee_change_log.operator_type','eb_employee_change_log.operator_id',
    'eb_employee_change_log.operator_name','eb_employee_change_log.operator_ip',
    'eb_employee_change_log.request_id','eb_employee_change_log.add_time'
  );

SET @asidei_dependency_count := 0;
SET @asidei_registered := 0;
SET @asidei_log_sql := IF(
  @asidei_required_tables=4,
  'SELECT COALESCE(SUM(upgrade_key IN (''20260729-005-employee-employment-type-authority'',''20260801-001-store-staff-cashier-role-eligibility'')),0),COALESCE(SUM(upgrade_key=''20260801-002-active-store-employee-default-internal''),0) INTO @asidei_dependency_count,@asidei_registered FROM eb_database_upgrade_log',
  'SELECT 0,0 INTO @asidei_dependency_count,@asidei_registered'
);
PREPARE asidei_log_stmt FROM @asidei_log_sql;
EXECUTE asidei_log_stmt;
DEALLOCATE PREPARE asidei_log_stmt;

SET @asidei_invalid_type_rows := 0;
SET @asidei_invalid_sql := IF(
  @asidei_required_columns=24,
  'SELECT COUNT(*) INTO @asidei_invalid_type_rows FROM eb_employee WHERE NOT ((employment_type_code IS NULL AND employment_type_version=0) OR (employment_type_code IN (''internal'',''partner'',''outsourced'') AND employment_type_version>0))',
  'SELECT 0 INTO @asidei_invalid_type_rows'
);
PREPARE asidei_invalid_stmt FROM @asidei_invalid_sql;
EXECUTE asidei_invalid_stmt;
DEALLOCATE PREPARE asidei_invalid_stmt;

SET @asidei_target_count := 0;
SET @asidei_target_sql := IF(
  @asidei_required_columns=24,
  'SELECT COUNT(DISTINCT employee.id) INTO @asidei_target_count
   FROM eb_employee employee
   INNER JOIN eb_system_store_staff staff
     ON staff.employee_id=employee.id AND staff.status=1 AND staff.is_del=0
   WHERE employee.status=1 AND employee.is_del=0
     AND employee.employment_type_code IS NULL
     AND employee.employment_type_version=0
     AND NOT EXISTS (
       SELECT 1 FROM eb_system_store_staff flagged
       WHERE flagged.employee_id=employee.id
         AND flagged.status=1 AND flagged.is_del=0
         AND (flagged.is_hezuofang=1 OR flagged.is_fencheng=1)
     )',
  'SELECT 0 INTO @asidei_target_count'
);
PREPARE asidei_target_stmt FROM @asidei_target_sql;
EXECUTE asidei_target_stmt;
DEALLOCATE PREPARE asidei_target_stmt;

SET @asidei_failures := @asidei_failures
  + IF(@asidei_required_tables=4,0,1)
  + IF(@asidei_required_columns=24,0,1)
  + IF(@asidei_dependency_count=2,0,1)
  + IF(@asidei_registered=0,0,1)
  + IF(@asidei_invalid_type_rows=0,0,1);

SELECT
  @asidei_db AS db_name,
  VERSION() AS mysql_version,
  @asidei_required_tables AS required_table_count,
  @asidei_required_columns AS required_column_count,
  @asidei_dependency_count AS dependency_upgrade_count,
  @asidei_registered AS already_registered,
  @asidei_invalid_type_rows AS invalid_employee_type_count,
  @asidei_target_count AS planned_default_internal_count,
  @asidei_failures AS precheck_failure_count;

SET @asidei_finish_sql := IF(
  @asidei_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_ACTIVE_STORE_EMPLOYEE_DEFAULT_INTERNAL_PRECHECK_FAILED'
);
PREPARE asidei_finish_stmt FROM @asidei_finish_sql;
EXECUTE asidei_finish_stmt;
DEALLOCATE PREPARE asidei_finish_stmt;
