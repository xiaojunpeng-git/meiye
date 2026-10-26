SET NAMES utf8mb4;
SELECT DATABASE() AS target_database;
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_employee'
  AND COLUMN_NAME = 'status_version';
SELECT COUNT(*) AS invalid_status_version
FROM eb_employee WHERE status_version IS NULL OR status_version < 0;
SELECT COUNT(*) AS logged_upgrade
FROM eb_database_upgrade_log WHERE upgrade_key = '20260923-002-employee-departure-records';
