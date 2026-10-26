-- upgrade_key: 20260729-005-employee-employment-type-authority
SET NAMES utf8mb4;
SET @eta_db := DATABASE();
SET @eta_failures := 0;

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

SELECT COUNT(*) INTO @eta_invalid_rows
FROM eb_employee
WHERE NOT (
  (employment_type_code IS NULL AND employment_type_version=0)
  OR
  (employment_type_code IN ('internal','partner','outsourced') AND employment_type_version>0)
);

SELECT COUNT(*) INTO @eta_unclassified_count
FROM eb_employee
WHERE employment_type_code IS NULL
  AND employment_type_version=0;

SELECT COUNT(*) INTO @eta_target_exact
FROM eb_system_menus child
INNER JOIN eb_system_menus parent ON parent.id=child.pid
WHERE child.unique_auth='setting-staff-employment-type'
  AND child.is_del=0 AND child.type=1 AND child.auth_type=2 AND child.is_show=0
  AND parent.unique_auth='setting-staff-index' AND parent.is_del=0 AND parent.type=1;

SELECT COUNT(DISTINCT employee.id) INTO @eta_manual_count
FROM eb_employee employee
INNER JOIN eb_system_store_staff staff
  ON staff.employee_id=employee.id
  AND staff.is_del=0 AND staff.status=1
  AND (staff.is_fencheng=1 OR staff.is_hezuofang=1)
WHERE employee.employment_type_code IS NULL
  AND employee.employment_type_version=0;

SET @eta_failures := @eta_failures
  + IF(@eta_exact_columns=2,0,1)
  + IF(@eta_invalid_rows=0,0,1)
  + IF(@eta_target_exact=1,0,1);

SELECT
  @eta_exact_columns AS exact_authority_column_count,
  @eta_invalid_rows AS invalid_employee_type_count,
  @eta_unclassified_count AS unclassified_employee_count,
  @eta_manual_count AS manual_classification_count,
  @eta_target_exact AS permission_menu_count,
  @eta_failures AS verification_failure_count;

SELECT
  employee.id AS employee_id,
  employee.name AS employee_name,
  employee.phone AS employee_phone,
  GROUP_CONCAT(DISTINCT staff.store_id ORDER BY staff.store_id) AS active_store_ids,
  MAX(staff.is_fencheng) AS has_legacy_share_flag,
  MAX(staff.is_hezuofang) AS has_legacy_partner_flag,
  '人工选择 partner 或 outsourced' AS required_action
FROM eb_employee employee
INNER JOIN eb_system_store_staff staff
  ON staff.employee_id=employee.id
  AND staff.is_del=0 AND staff.status=1
  AND (staff.is_fencheng=1 OR staff.is_hezuofang=1)
WHERE employee.employment_type_code IS NULL
  AND employee.employment_type_version=0
GROUP BY employee.id,employee.name,employee.phone
ORDER BY employee.id;

SET @eta_finish_sql := IF(
  @eta_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_EMPLOYEE_TYPE_AUTHORITY_POSTCHECK_FAILED'
);
PREPARE eta_finish_stmt FROM @eta_finish_sql;
EXECUTE eta_finish_stmt;
DEALLOCATE PREPARE eta_finish_stmt;
