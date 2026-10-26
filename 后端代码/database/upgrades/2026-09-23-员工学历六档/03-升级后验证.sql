SET NAMES utf8mb4;
SELECT DATABASE() AS target_database;
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_employee'
  AND COLUMN_NAME IN ('education', 'education_version')
ORDER BY COLUMN_NAME;
SELECT education, COUNT(*) AS employee_rows
FROM eb_employee WHERE is_del = 0 GROUP BY education ORDER BY education;
SELECT COUNT(*) AS logged_upgrade
FROM eb_database_upgrade_log WHERE upgrade_key = '20260923-001-employee-education-six-levels';
