-- upgrade_key: 20260808-001-platform-menu-sort-range
SET NAMES utf8mb4;

SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_system_menus'
  AND COLUMN_NAME = 'sort';

SELECT id, menu_name, pid, `sort`
FROM `eb_system_menus`
WHERE id = 1572;
