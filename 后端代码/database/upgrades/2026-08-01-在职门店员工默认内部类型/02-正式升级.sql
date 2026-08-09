-- upgrade_key: 20260801-002-active-store-employee-default-internal
-- MySQL 5.6.51 compatible. Replay-safe data migration.
SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS tmp_active_store_default_internal;
CREATE TEMPORARY TABLE tmp_active_store_default_internal (
  employee_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY (employee_id)
) ENGINE=InnoDB;

START TRANSACTION;

INSERT INTO tmp_active_store_default_internal (employee_id)
SELECT DISTINCT employee.id
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
  )
FOR UPDATE;

INSERT INTO eb_employee_change_log
  (employee_id,action,target_type,target_id,source,before_data,after_data,reason,
   operator_type,operator_id,operator_name,operator_ip,request_id,add_time)
SELECT
  target.employee_id,
  'employee_employment_type_default_internal',
  'employee_employment_type',
  target.employee_id,
  'migration',
  '{"employment_type_code":null,"employment_type_version":0}',
  '{"employment_type_code":"internal","employment_type_version":1}',
  '在职门店员工默认归类为内部员工',
  'system',
  0,
  'database_upgrade',
  '',
  '20260801-002-active-store-employee-default-internal',
  UNIX_TIMESTAMP()
FROM tmp_active_store_default_internal target
INNER JOIN eb_employee employee ON employee.id=target.employee_id
WHERE employee.status=1
  AND employee.is_del=0
  AND employee.employment_type_code IS NULL
  AND employee.employment_type_version=0;

UPDATE eb_employee employee
INNER JOIN tmp_active_store_default_internal target ON target.employee_id=employee.id
SET employee.employment_type_code='internal',
    employee.employment_type_version=1,
    employee.update_time=UNIX_TIMESTAMP()
WHERE employee.status=1
  AND employee.is_del=0
  AND employee.employment_type_code IS NULL
  AND employee.employment_type_version=0;

COMMIT;

SELECT COUNT(*) AS selected_employee_count
FROM tmp_active_store_default_internal;

DROP TEMPORARY TABLE IF EXISTS tmp_active_store_default_internal;
SELECT 'APPLY_OK' AS apply_result;
