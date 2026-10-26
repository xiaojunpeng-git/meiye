-- upgrade_key: 20260814-003-employee-craftsman-performance-type
SET NAMES utf8mb4;
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_system_store_staff'
  AND COLUMN_NAME='craftsman_performance_type';
SELECT craftsman_performance_type, COUNT(*) AS total
FROM `eb_system_store_staff`
GROUP BY craftsman_performance_type
ORDER BY craftsman_performance_type;
SELECT COUNT(*) AS invalid_count
FROM `eb_system_store_staff`
WHERE craftsman_performance_type NOT IN ('commission','labor','commission_labor');
