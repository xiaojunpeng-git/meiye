-- 必须在目标客户独立数据库执行；不写数据。
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_system_store_staff','eb_employee','eb_employee_change_log','eb_staff_store_v3_feature_override');

SELECT COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_employee'
  AND COLUMN_NAME IN ('id','auth_version','status','is_del');

SELECT COUNT(*) AS active_store_staff_without_employee
FROM eb_system_store_staff
WHERE status = 1 AND is_del = 0 AND IFNULL(employee_id, 0) = 0;
