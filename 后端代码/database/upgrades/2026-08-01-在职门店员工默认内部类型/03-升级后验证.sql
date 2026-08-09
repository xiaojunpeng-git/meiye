-- upgrade_key: 20260801-002-active-store-employee-default-internal
-- Read-only postcheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @asidei_failures := 0;

SELECT COUNT(*) INTO @asidei_remaining_eligible
FROM eb_employee employee
INNER JOIN eb_system_store_staff staff
  ON staff.employee_id=employee.id
  AND staff.status=1
  AND staff.is_del=0
WHERE employee.status=1
  AND employee.is_del=0
  AND employee.employment_type_code IS NULL
  AND employee.employment_type_version=0
  AND NOT EXISTS (
    SELECT 1
    FROM eb_system_store_staff flagged
    WHERE flagged.employee_id=employee.id
      AND flagged.status=1
      AND flagged.is_del=0
      AND (flagged.is_hezuofang=1 OR flagged.is_fencheng=1)
  );

SELECT COUNT(*) INTO @asidei_invalid_type_rows
FROM eb_employee
WHERE NOT (
  (employment_type_code IS NULL AND employment_type_version=0)
  OR
  (employment_type_code IN ('internal','partner','outsourced') AND employment_type_version>0)
);

SELECT COUNT(*) INTO @asidei_invalid_audit_targets
FROM eb_employee_change_log audit
LEFT JOIN eb_employee employee ON employee.id=audit.employee_id
WHERE audit.action='employee_employment_type_default_internal'
  AND (
    employee.id IS NULL
    OR employee.employment_type_code NOT IN ('internal','partner','outsourced')
    OR employee.employment_type_version<=0
  );

SELECT COUNT(*) INTO @asidei_audit_count
FROM eb_employee_change_log
WHERE action='employee_employment_type_default_internal';

SELECT COUNT(DISTINCT employee.id) INTO @asidei_manual_review_count
FROM eb_employee employee
INNER JOIN eb_system_store_staff staff
  ON staff.employee_id=employee.id
  AND staff.status=1
  AND staff.is_del=0
  AND (staff.is_hezuofang=1 OR staff.is_fencheng=1)
WHERE employee.status=1
  AND employee.is_del=0
  AND employee.employment_type_code IS NULL
  AND employee.employment_type_version=0;

SET @asidei_failures := @asidei_failures
  + IF(@asidei_remaining_eligible=0,0,1)
  + IF(@asidei_invalid_type_rows=0,0,1)
  + IF(@asidei_invalid_audit_targets=0,0,1);

SELECT
  @asidei_remaining_eligible AS remaining_eligible_unclassified_count,
  @asidei_invalid_type_rows AS invalid_employee_type_count,
  @asidei_invalid_audit_targets AS invalid_audit_target_count,
  @asidei_audit_count AS default_internal_audit_count,
  @asidei_manual_review_count AS manual_review_employee_count,
  @asidei_failures AS postcheck_failure_count;

SET @asidei_finish_sql := IF(
  @asidei_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_ACTIVE_STORE_EMPLOYEE_DEFAULT_INTERNAL_POSTCHECK_FAILED'
);
PREPARE asidei_finish_stmt FROM @asidei_finish_sql;
EXECUTE asidei_finish_stmt;
DEALLOCATE PREPARE asidei_finish_stmt;
