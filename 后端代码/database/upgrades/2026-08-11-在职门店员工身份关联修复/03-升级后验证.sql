-- upgrade_key: 20260811-003-active-store-staff-employee-identity-repair
-- Read-only postcheck. It may run before and after the external upgrade-log registration.
SET NAMES utf8mb4;
SET @sier_db := DATABASE();
SET @sier_key := '20260811-003-active-store-staff-employee-identity-repair';
SET @sier_failures := 0;

SELECT COUNT(*) INTO @sier_phone_nullable
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@sier_db AND TABLE_NAME='eb_employee' AND COLUMN_NAME='phone'
  AND COLUMN_TYPE='char(11)' AND IS_NULLABLE='YES';

SELECT COUNT(*) INTO @sier_invalid_active
FROM eb_system_store_staff staff
LEFT JOIN eb_employee employee ON employee.id=staff.employee_id
WHERE staff.status=1 AND staff.is_del=0
  AND (staff.employee_id IS NULL OR staff.employee_id=0 OR employee.id IS NULL
       OR employee.status<>1 OR employee.is_del<>0);

SELECT COUNT(*) INTO @sier_audit_count
FROM eb_employee_change_log
WHERE action='employee_identity_repaired_from_staff' AND request_id=@sier_key;

SELECT COUNT(*) INTO @sier_invalid_audit
FROM eb_employee_change_log audit
LEFT JOIN eb_system_store_staff staff ON staff.id=audit.target_id
LEFT JOIN eb_employee employee ON employee.id=audit.employee_id
WHERE audit.action='employee_identity_repaired_from_staff' AND audit.request_id=@sier_key
  AND (audit.target_type<>'store_staff' OR audit.source<>'migration' OR staff.id IS NULL
       OR staff.employee_id<>audit.employee_id OR employee.id IS NULL
       OR employee.status<>1 OR employee.is_del<>0);

SELECT COUNT(*) INTO @sier_null_phone_repair
FROM eb_employee_change_log audit
INNER JOIN eb_employee employee ON employee.id=audit.employee_id
WHERE audit.action='employee_identity_repaired_from_staff' AND audit.request_id=@sier_key
  AND audit.before_data LIKE '%"sourceType":"staff_phone"%'
  AND (employee.phone IS NULL OR employee.phone NOT REGEXP '^1[3-9][0-9]{9}$');

SELECT COUNT(*) INTO @sier_registered
FROM eb_database_upgrade_log WHERE upgrade_key=@sier_key;

SET @sier_failures := @sier_failures
  + IF(@sier_phone_nullable=1,0,1)
  + IF(@sier_invalid_active=0,0,1)
  + IF(@sier_invalid_audit=0,0,1)
  + IF(@sier_null_phone_repair=0,0,1)
  + IF(@sier_registered IN (0,1),0,1);

SELECT @sier_phone_nullable AS employee_phone_nullable,
  @sier_invalid_active AS invalid_active_assignment_count,
  @sier_audit_count AS identity_repair_audit_count,
  @sier_invalid_audit AS invalid_identity_repair_audit_count,
  @sier_null_phone_repair AS invalid_phone_source_repair_count,
  @sier_registered AS upgrade_log_count,
  @sier_failures AS postcheck_failure_count;

SET @sier_finish_sql := IF(@sier_failures<>0,
  'SELECT * FROM STOP_ACTIVE_STORE_STAFF_IDENTITY_POSTCHECK_FAILED',
  IF(@sier_registered=1,
    'SELECT ''POSTCHECK_OK'' AS postcheck_result',
    'SELECT ''POSTCHECK_READY_FOR_UPGRADE_LOG'' AS postcheck_result'
  )
);
PREPARE sier_finish_stmt FROM @sier_finish_sql;
EXECUTE sier_finish_stmt;
DEALLOCATE PREPARE sier_finish_stmt;
