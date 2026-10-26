-- upgrade_key: 20260915-001-cashier-v3-reservation-time-window-v1
SET NAMES utf8mb4;
SET @reservation_window_db := DATABASE();

SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@reservation_window_db
  AND TABLE_NAME IN ('eb_cashier_v3_reservation','eb_cashier_v3_reservation_line','eb_system_store_staff')
ORDER BY TABLE_NAME;

SELECT 'PRECHECK_OK' AS precheck_result;
