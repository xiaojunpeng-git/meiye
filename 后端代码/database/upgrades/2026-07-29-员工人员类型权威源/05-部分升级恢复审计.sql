-- upgrade_key: 20260729-005-employee-employment-type-authority
-- Read-only interrupted-upgrade audit. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @eta_db := DATABASE();

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

SET @eta_column_data_invalid := 0;
SET @eta_column_data_sql := CASE
  WHEN @eta_existing_columns=0 THEN 'SELECT 0 INTO @eta_column_data_invalid'
  WHEN @eta_existing_columns=1 AND (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=@eta_db AND TABLE_NAME='eb_employee'
      AND COLUMN_NAME='employment_type_code'
  )=1 THEN 'SELECT COUNT(*) INTO @eta_column_data_invalid FROM eb_employee WHERE employment_type_code IS NOT NULL'
  WHEN @eta_existing_columns=1 THEN 'SELECT COUNT(*) INTO @eta_column_data_invalid FROM eb_employee WHERE employment_type_version<>0'
  ELSE 'SELECT COUNT(*) INTO @eta_column_data_invalid FROM eb_employee WHERE NOT ((employment_type_code IS NULL AND employment_type_version=0) OR (employment_type_code IN (''internal'',''partner'',''outsourced'') AND employment_type_version>0))'
END;
PREPARE eta_column_data_stmt FROM @eta_column_data_sql;
EXECUTE eta_column_data_stmt;
DEALLOCATE PREPARE eta_column_data_stmt;

SELECT COUNT(*) INTO @eta_target_count
FROM eb_system_menus
WHERE unique_auth='setting-staff-employment-type' AND is_del=0;

SELECT COUNT(*) INTO @eta_target_exact
FROM eb_system_menus child
INNER JOIN eb_system_menus parent ON parent.id=child.pid
WHERE child.unique_auth='setting-staff-employment-type'
  AND child.is_del=0 AND child.type=1 AND child.auth_type=2 AND child.is_show=0
  AND parent.unique_auth='setting-staff-index' AND parent.is_del=0 AND parent.type=1;

SET @eta_partial_state := (@eta_existing_columns > 0 OR @eta_target_count > 0);
SET @eta_recovery_ready := @eta_partial_state=1
  AND @eta_upgrade_log_exists=1
  AND @eta_registered=0
  AND @eta_existing_columns IN (0,1,2)
  AND @eta_exact_columns=@eta_existing_columns
  AND @eta_column_data_invalid=0
  AND @eta_target_count IN (0,1)
  AND @eta_target_exact=@eta_target_count;

SELECT
  @eta_existing_columns AS existing_authority_column_count,
  @eta_exact_columns AS exact_authority_column_count,
  @eta_column_data_invalid AS unsafe_partial_data_count,
  @eta_target_count AS target_menu_count,
  @eta_target_exact AS exact_target_menu_count,
  @eta_registered AS upgrade_registered,
  @eta_recovery_ready AS partial_recovery_ready;

SET @eta_finish_sql := CASE
  WHEN @eta_partial_state=0 THEN 'SELECT * FROM STOP_EMPLOYEE_TYPE_PARTIAL_FRESH_STATE'
  WHEN @eta_upgrade_log_exists<>1 THEN 'SELECT * FROM STOP_EMPLOYEE_TYPE_PARTIAL_UPGRADE_LOG_MISSING'
  WHEN @eta_registered<>0 THEN 'SELECT * FROM STOP_EMPLOYEE_TYPE_PARTIAL_ALREADY_REGISTERED'
  WHEN @eta_existing_columns NOT IN (0,1,2) OR @eta_exact_columns<>@eta_existing_columns THEN 'SELECT * FROM STOP_EMPLOYEE_TYPE_PARTIAL_HETEROGENEOUS_COLUMNS'
  WHEN @eta_column_data_invalid<>0 THEN 'SELECT * FROM STOP_EMPLOYEE_TYPE_PARTIAL_UNSAFE_DATA'
  WHEN @eta_target_count NOT IN (0,1) OR @eta_target_exact<>@eta_target_count THEN 'SELECT * FROM STOP_EMPLOYEE_TYPE_PARTIAL_HETEROGENEOUS_MENU'
  ELSE 'SELECT ''PARTIAL_UPGRADE_RECOVERY_READY'' AS recovery_result'
END;
PREPARE eta_finish_stmt FROM @eta_finish_sql;
EXECUTE eta_finish_stmt;
DEALLOCATE PREPARE eta_finish_stmt;
