-- upgrade_key: 20260811-003-active-store-staff-employee-identity-repair
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @sier_db := DATABASE();
SET @sier_key := '20260811-003-active-store-staff-employee-identity-repair';
SET @sier_failures := 0;

SELECT COUNT(*) INTO @sier_required_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@sier_db
  AND TABLE_NAME IN ('eb_database_upgrade_log','eb_employee','eb_system_store_staff','eb_employee_change_log')
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @sier_required_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@sier_db
  AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
    'eb_employee.id','eb_employee.name','eb_employee.phone','eb_employee.uid','eb_employee.status',
    'eb_employee.employment_type_code','eb_employee.employment_type_version','eb_employee.auth_version',
    'eb_employee.is_del','eb_employee.add_time','eb_employee.update_time',
    'eb_system_store_staff.id','eb_system_store_staff.employee_id','eb_system_store_staff.store_id',
    'eb_system_store_staff.uid','eb_system_store_staff.account','eb_system_store_staff.staff_name',
    'eb_system_store_staff.phone','eb_system_store_staff.status','eb_system_store_staff.is_del',
    'eb_system_store_staff.is_hezuofang','eb_system_store_staff.is_fencheng',
    'eb_employee_change_log.employee_id','eb_employee_change_log.action','eb_employee_change_log.target_type',
    'eb_employee_change_log.target_id','eb_employee_change_log.source','eb_employee_change_log.before_data',
    'eb_employee_change_log.after_data','eb_employee_change_log.reason','eb_employee_change_log.operator_type',
    'eb_employee_change_log.operator_id','eb_employee_change_log.operator_name','eb_employee_change_log.operator_ip',
    'eb_employee_change_log.request_id','eb_employee_change_log.add_time'
  );

SELECT COUNT(*) INTO @sier_dependencies
FROM eb_database_upgrade_log
WHERE upgrade_key IN ('20260719-009-employee-org-leader','20260727-001-cashier-v3-command-idem');
SELECT COUNT(*) INTO @sier_registered
FROM eb_database_upgrade_log WHERE upgrade_key=@sier_key;
SELECT COUNT(*) INTO @sier_prior_audit
FROM eb_employee_change_log
WHERE action='employee_identity_repaired_from_staff' AND request_id=@sier_key;

SELECT COUNT(*) INTO @sier_invalid_active
FROM eb_system_store_staff staff
LEFT JOIN eb_employee employee ON employee.id=staff.employee_id
WHERE staff.status=1 AND staff.is_del=0
  AND (staff.employee_id IS NULL OR staff.employee_id=0 OR employee.id IS NULL
       OR employee.status<>1 OR employee.is_del<>0);

SELECT COUNT(*) INTO @sier_uid_candidates
FROM eb_system_store_staff staff
LEFT JOIN eb_employee current_employee ON current_employee.id=staff.employee_id
WHERE staff.status=1 AND staff.is_del=0
  AND (staff.employee_id IS NULL OR staff.employee_id=0 OR current_employee.id IS NULL
       OR current_employee.status<>1 OR current_employee.is_del<>0)
  AND staff.uid>0
  AND NOT EXISTS (SELECT 1 FROM eb_employee employee WHERE employee.uid=staff.uid)
  AND NOT EXISTS (
    SELECT 1 FROM eb_system_store_staff peer
    WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.uid=staff.uid
  );

SELECT COUNT(*) INTO @sier_account_candidates
FROM eb_system_store_staff staff
LEFT JOIN eb_employee current_employee ON current_employee.id=staff.employee_id
WHERE staff.status=1 AND staff.is_del=0
  AND (staff.employee_id IS NULL OR staff.employee_id=0 OR current_employee.id IS NULL
       OR current_employee.status<>1 OR current_employee.is_del<>0)
  AND NOT (
    staff.uid>0
    AND NOT EXISTS (SELECT 1 FROM eb_employee employee WHERE employee.uid=staff.uid)
    AND NOT EXISTS (
      SELECT 1 FROM eb_system_store_staff peer
      WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.uid=staff.uid
    )
  )
  AND staff.account<>''
  AND NOT EXISTS (
    SELECT 1 FROM eb_system_store_staff peer
    WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.account=staff.account
  );

SELECT COUNT(*) INTO @sier_phone_candidates
FROM eb_system_store_staff staff
LEFT JOIN eb_employee current_employee ON current_employee.id=staff.employee_id
WHERE staff.status=1 AND staff.is_del=0
  AND (staff.employee_id IS NULL OR staff.employee_id=0 OR current_employee.id IS NULL
       OR current_employee.status<>1 OR current_employee.is_del<>0)
  AND NOT (
    staff.uid>0
    AND NOT EXISTS (SELECT 1 FROM eb_employee employee WHERE employee.uid=staff.uid)
    AND NOT EXISTS (
      SELECT 1 FROM eb_system_store_staff peer
      WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.uid=staff.uid
    )
  )
  AND NOT (
    staff.account<>''
    AND NOT EXISTS (
      SELECT 1 FROM eb_system_store_staff peer
      WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.account=staff.account
    )
  )
  AND staff.phone REGEXP '^1[3-9][0-9]{9}$'
  AND NOT EXISTS (SELECT 1 FROM eb_employee employee WHERE employee.phone=staff.phone)
  AND NOT EXISTS (
    SELECT 1 FROM eb_system_store_staff peer
    WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.phone=staff.phone
  );

SET @sier_resolved_candidates := @sier_uid_candidates + @sier_account_candidates + @sier_phone_candidates;
SET @sier_unresolved := @sier_invalid_active - @sier_resolved_candidates;

SET @sier_failures := @sier_failures
  + IF(@sier_required_tables=4,0,1)
  + IF(@sier_required_columns=36,0,1)
  + IF(@sier_dependencies=2,0,1)
  + IF(@sier_registered=0,0,1)
  + IF(@sier_prior_audit=0,0,1)
  + IF(@sier_unresolved=0,0,1);

SELECT @sier_db AS db_name, VERSION() AS mysql_version,
  @sier_required_tables AS required_table_count,
  @sier_required_columns AS required_column_count,
  @sier_dependencies AS dependency_upgrade_count,
  @sier_registered AS already_registered,
  @sier_invalid_active AS invalid_active_assignment_count,
  @sier_uid_candidates AS uid_candidate_count,
  @sier_account_candidates AS account_candidate_count,
  @sier_phone_candidates AS phone_candidate_count,
  @sier_unresolved AS unresolved_assignment_count,
  @sier_prior_audit AS prior_audit_count,
  @sier_failures AS precheck_failure_count;

SET @sier_finish_sql := IF(@sier_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_ACTIVE_STORE_STAFF_IDENTITY_PRECHECK_FAILED');
PREPARE sier_finish_stmt FROM @sier_finish_sql;
EXECUTE sier_finish_stmt;
DEALLOCATE PREPARE sier_finish_stmt;
