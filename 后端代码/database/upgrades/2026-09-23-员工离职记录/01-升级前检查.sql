SET NAMES utf8mb4;
SELECT DATABASE() AS target_database;
SELECT COUNT(*) AS employee_rows FROM eb_employee;
SELECT COUNT(*) AS tenure_rows FROM eb_staff_tenure_period;
SELECT COUNT(*) AS departure_fact_rows
FROM eb_staff_tenure_period WHERE action = 'leave' AND is_del = 0;
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_employee'
  AND COLUMN_NAME = 'status_version';
