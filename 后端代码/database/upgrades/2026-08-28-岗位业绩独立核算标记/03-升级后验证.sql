SET NAMES utf8mb4;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_position'
  AND COLUMN_NAME = 'performance_independent';

SELECT COUNT(*) AS invalid_value_count
FROM `eb_position`
WHERE `performance_independent` NOT IN (0, 1);

SELECT `id`, `name`, `performance_independent`
FROM `eb_position`
ORDER BY `id`
LIMIT 20;
