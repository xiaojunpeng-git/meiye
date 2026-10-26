SET NAMES utf8mb4;

SELECT DATABASE() AS database_name;
SELECT COUNT(*) AS position_count FROM `eb_position`;
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_position'
  AND COLUMN_NAME = 'performance_independent';
