SET NAMES utf8mb4;
SELECT DATABASE() AS target_database;
SELECT COUNT(*) AS employee_rows FROM eb_employee;
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_employee'
  AND COLUMN_NAME IN ('education', 'education_version')
ORDER BY COLUMN_NAME;
