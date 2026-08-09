SET @upgrade_key := '20260802-001-legacy-store-admin-employee-identity';
SELECT COUNT(*) INTO @logged FROM eb_database_upgrade_log WHERE upgrade_key=@upgrade_key;
SELECT COUNT(*) INTO @invalid FROM eb_system_store_staff s
LEFT JOIN eb_employee e ON e.id=s.employee_id
WHERE s.status=1 AND s.is_del=0
  AND (s.employee_id IS NULL OR s.employee_id=0 OR e.id IS NULL OR e.status<>1 OR e.is_del<>0);
SELECT COUNT(*) INTO @eligible_admins FROM eb_system_store_staff
WHERE status=1 AND is_del=0 AND account REGEXP '^rh_store_admin_[0-9]+$'
  AND (cashier_salesperson_enabled<>0 OR cashier_craftsman_enabled<>0);
SELECT @logged AS upgrade_log_count,@invalid AS invalid_active_assignment_count,@eligible_admins AS legacy_admin_business_role_count;
SET @finish := IF(@invalid=0 AND @eligible_admins=0,
 'SELECT ''POSTCHECK_OK'' AS postcheck_result','SELECT * FROM STOP_LEGACY_ADMIN_EMPLOYEE_POSTCHECK_FAILED');
PREPARE stmt FROM @finish; EXECUTE stmt; DEALLOCATE PREPARE stmt;
