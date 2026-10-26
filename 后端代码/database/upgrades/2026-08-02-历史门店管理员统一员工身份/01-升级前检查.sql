SET @upgrade_key := '20260802-001-legacy-store-admin-employee-identity';
SELECT COUNT(*) INTO @used FROM eb_database_upgrade_log WHERE upgrade_key=@upgrade_key;
SELECT COUNT(*) INTO @targets FROM eb_system_store_staff
 WHERE status=1 AND is_del=0 AND (employee_id IS NULL OR employee_id=0)
   AND account REGEXP '^rh_store_admin_[0-9]+$';
SELECT COUNT(*) INTO @unexpected FROM eb_system_store_staff
 WHERE status=1 AND is_del=0 AND (employee_id IS NULL OR employee_id=0)
   AND account NOT REGEXP '^rh_store_admin_[0-9]+$'
   AND phone NOT REGEXP '^1[0-9]{10}$';
SELECT COUNT(*) INTO @phone_conflicts FROM eb_system_store_staff s
 JOIN eb_employee e ON e.phone=CONCAT('198',LPAD(s.id,8,'0'))
 WHERE s.status=1 AND s.is_del=0 AND (s.employee_id IS NULL OR s.employee_id=0)
   AND s.account REGEXP '^rh_store_admin_[0-9]+$';
SELECT @used AS upgrade_key_used,@targets AS target_count,@unexpected AS unexpected_count,@phone_conflicts AS phone_conflict_count;
SET @finish := IF(@used=0 AND @targets>0 AND @unexpected=0 AND @phone_conflicts=0,
 'SELECT ''PRECHECK_OK'' AS precheck_result','SELECT * FROM STOP_LEGACY_ADMIN_EMPLOYEE_PRECHECK_FAILED');
PREPARE stmt FROM @finish; EXECUTE stmt; DEALLOCATE PREPARE stmt;
