-- upgrade_key: 20260729-005-employee-employment-type-authority
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @eta_db := DATABASE();
SET @eta_failures := 0;

SELECT COUNT(*) INTO @eta_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@eta_db AND TABLE_NAME='eb_database_upgrade_log';

SET @eta_registered := 0;
SET @eta_registered_sql := IF(
  @eta_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @eta_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-005-employee-employment-type-authority''',
  'SELECT 0 INTO @eta_registered'
);
PREPARE eta_registered_stmt FROM @eta_registered_sql;
EXECUTE eta_registered_stmt;
DEALLOCATE PREPARE eta_registered_stmt;

SELECT COUNT(*) INTO @eta_dependency_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@eta_db
  AND TABLE_NAME IN (
    'eb_employee','eb_system_store_staff','eb_employee_change_log',
    'eb_system_admin','eb_system_role','eb_system_menus',
    'eb_organization_write_idempotency'
  )
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @eta_dependency_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@eta_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_employee.id','eb_employee.status','eb_employee.is_del',
  'eb_system_store_staff.id','eb_system_store_staff.employee_id',
  'eb_system_store_staff.store_id','eb_system_store_staff.status',
  'eb_system_store_staff.is_del','eb_system_store_staff.is_fencheng',
  'eb_system_store_staff.is_hezuofang',
  'eb_employee_change_log.employee_id','eb_employee_change_log.action',
  'eb_employee_change_log.target_type','eb_employee_change_log.target_id',
  'eb_employee_change_log.source','eb_employee_change_log.before_data',
  'eb_employee_change_log.after_data','eb_employee_change_log.reason',
  'eb_employee_change_log.operator_type','eb_employee_change_log.operator_id',
  'eb_employee_change_log.operator_name','eb_employee_change_log.operator_ip',
  'eb_employee_change_log.request_id','eb_employee_change_log.add_time',
  'eb_system_admin.id','eb_system_admin.level','eb_system_admin.admin_type',
  'eb_system_admin.roles','eb_system_admin.status','eb_system_admin.is_del',
  'eb_system_role.id','eb_system_role.rules',
  'eb_system_menus.id','eb_system_menus.pid','eb_system_menus.type',
  'eb_system_menus.auth_type','eb_system_menus.unique_auth','eb_system_menus.is_del',
  'eb_organization_write_idempotency.request_token',
  'eb_organization_write_idempotency.operator_id',
  'eb_organization_write_idempotency.action',
  'eb_organization_write_idempotency.scope_key',
  'eb_organization_write_idempotency.request_hash',
  'eb_organization_write_idempotency.response_json'
);

SELECT COUNT(*) INTO @eta_existing_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@eta_db AND TABLE_NAME='eb_employee'
  AND COLUMN_NAME IN ('employment_type_code','employment_type_version');

SELECT COUNT(*) INTO @eta_exact_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@eta_db AND TABLE_NAME='eb_employee' AND (
  (COLUMN_NAME='employment_type_code' AND DATA_TYPE='varchar'
    AND CHARACTER_MAXIMUM_LENGTH=16 AND IS_NULLABLE='YES'
    AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
  OR
  (COLUMN_NAME='employment_type_version' AND DATA_TYPE='bigint'
    AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE='NO'
    AND COLUMN_DEFAULT='0')
);

SET @eta_invalid_existing := 0;
SET @eta_invalid_sql := IF(
  @eta_existing_columns=2,
  'SELECT COUNT(*) INTO @eta_invalid_existing FROM eb_employee WHERE NOT ((employment_type_code IS NULL AND employment_type_version=0) OR (employment_type_code IN (''internal'',''partner'',''outsourced'') AND employment_type_version>0))',
  'SELECT 0 INTO @eta_invalid_existing'
);
PREPARE eta_invalid_stmt FROM @eta_invalid_sql;
EXECUTE eta_invalid_stmt;
DEALLOCATE PREPARE eta_invalid_stmt;

SELECT COUNT(*) INTO @eta_parent_count
FROM eb_system_menus
WHERE unique_auth='setting-staff-index' AND is_del=0 AND type=1;

SELECT COUNT(*) INTO @eta_target_count
FROM eb_system_menus
WHERE unique_auth='setting-staff-employment-type' AND is_del=0;

SELECT COUNT(*) INTO @eta_target_exact
FROM eb_system_menus child
INNER JOIN eb_system_menus parent ON parent.id=child.pid
WHERE child.unique_auth='setting-staff-employment-type'
  AND child.is_del=0 AND child.type=1 AND child.auth_type=2 AND child.is_show=0
  AND parent.unique_auth='setting-staff-index' AND parent.is_del=0 AND parent.type=1;

SET @eta_failures := @eta_failures
  + IF(@eta_upgrade_log_exists=1,0,1)
  + IF(@eta_registered=0,0,1)
  + IF(@eta_dependency_tables=7,0,1)
  + IF(@eta_dependency_columns=44,0,1)
  + IF(@eta_existing_columns IN (0,1,2),0,1)
  + IF(@eta_exact_columns=@eta_existing_columns,0,1)
  + IF(@eta_invalid_existing=0,0,1)
  + IF(@eta_parent_count=1,0,1)
  + IF(@eta_target_count IN (0,1),0,1)
  + IF(@eta_target_exact=@eta_target_count,0,1);

SELECT
  @eta_db AS db_name,
  VERSION() AS mysql_version,
  @eta_registered AS already_registered,
  @eta_dependency_tables AS dependency_table_count,
  @eta_dependency_columns AS dependency_column_count,
  @eta_existing_columns AS existing_authority_column_count,
  @eta_exact_columns AS exact_authority_column_count,
  @eta_invalid_existing AS invalid_existing_employee_count,
  @eta_parent_count AS parent_menu_count,
  @eta_target_count AS target_menu_count,
  @eta_failures AS precheck_failure_count;

SET @eta_finish_sql := IF(
  @eta_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_EMPLOYEE_TYPE_AUTHORITY_PRECHECK_FAILED'
);
PREPARE eta_finish_stmt FROM @eta_finish_sql;
EXECUTE eta_finish_stmt;
DEALLOCATE PREPARE eta_finish_stmt;
