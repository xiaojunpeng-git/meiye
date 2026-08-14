-- upgrade_key: 20260814-003-employee-craftsman-performance-type
SET NAMES utf8mb4;
SELECT DATABASE() AS db_name;
SELECT COUNT(*) AS staff_count FROM `eb_system_store_staff`;
SELECT COUNT(*) AS column_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_system_store_staff'
  AND COLUMN_NAME = 'craftsman_performance_type';
