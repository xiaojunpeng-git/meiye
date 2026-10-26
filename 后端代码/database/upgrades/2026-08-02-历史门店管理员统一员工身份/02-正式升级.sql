SET @upgrade_key := '20260802-001-legacy-store-admin-employee-identity';
START TRANSACTION;

INSERT INTO eb_employee
  (name,phone,status,employment_type_code,employment_type_version,auth_version,is_del,add_time,update_time)
SELECT COALESCE(NULLIF(TRIM(s.staff_name),''),'未命名员工'),s.phone,1,'internal',1,1,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM eb_system_store_staff s
LEFT JOIN eb_employee e ON e.phone=s.phone
WHERE s.status=1 AND s.is_del=0 AND (s.employee_id IS NULL OR s.employee_id=0)
  AND s.account NOT REGEXP '^rh_store_admin_[0-9]+$'
  AND s.phone REGEXP '^1[0-9]{10}$' AND e.id IS NULL
ORDER BY s.id;

INSERT INTO eb_employee
  (name,phone,status,employment_type_code,employment_type_version,auth_version,is_del,add_time,update_time)
SELECT COALESCE(NULLIF(TRIM(s.staff_name),''),CONCAT('门店系统管理员',s.store_id)),
       CONCAT('198',LPAD(s.id,8,'0')),1,'internal',1,1,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM eb_system_store_staff s
WHERE s.status=1 AND s.is_del=0 AND (s.employee_id IS NULL OR s.employee_id=0)
  AND s.account REGEXP '^rh_store_admin_[0-9]+$'
ORDER BY s.id;

UPDATE eb_system_store_staff s
JOIN eb_employee e ON e.phone=s.phone
SET s.employee_id=e.id
WHERE s.status=1 AND s.is_del=0 AND (s.employee_id IS NULL OR s.employee_id=0)
  AND s.account NOT REGEXP '^rh_store_admin_[0-9]+$'
  AND s.phone REGEXP '^1[0-9]{10}$';

UPDATE eb_system_store_staff s
JOIN eb_employee e ON e.phone=CONCAT('198',LPAD(s.id,8,'0'))
SET s.employee_id=e.id,
    s.cashier_salesperson_enabled=0,
    s.cashier_craftsman_enabled=0
WHERE s.status=1 AND s.is_del=0 AND (s.employee_id IS NULL OR s.employee_id=0)
  AND s.account REGEXP '^rh_store_admin_[0-9]+$';

COMMIT;
