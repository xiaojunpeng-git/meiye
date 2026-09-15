-- upgrade_key: 20260915-001-cashier-v3-reservation-time-window-v1
SET NAMES utf8mb4;
SET @reservation_window_db := DATABASE();

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@reservation_window_db
  AND TABLE_NAME='eb_cashier_v3_reservation_staff_schedule';

SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@reservation_window_db
  AND TABLE_NAME='eb_cashier_v3_reservation_staff_schedule'
  AND COLUMN_NAME='is_point_customer';

SELECT INDEX_NAME, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@reservation_window_db
  AND TABLE_NAME='eb_cashier_v3_reservation_staff_schedule'
  AND INDEX_NAME IN ('uk_tenant_reservation_staff','idx_tenant_staff_reservation','idx_tenant_reservation')
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT COUNT(*) AS upgrade_log_count
FROM eb_database_upgrade_log
WHERE upgrade_key='20260915-001-cashier-v3-reservation-time-window-v1';

SELECT 'POSTCHECK_OK' AS postcheck_result;
