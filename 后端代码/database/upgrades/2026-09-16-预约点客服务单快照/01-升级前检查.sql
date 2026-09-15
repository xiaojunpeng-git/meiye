-- upgrade_key: 20260916-001-cashier-v3-reservation-point-customer-service-snapshot-v1
SET NAMES utf8mb4;
SET @point_customer_service_db := DATABASE();

SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@point_customer_service_db
  AND TABLE_NAME IN ('eb_cashier_v3_service_order', 'eb_cashier_v3_reservation_staff_schedule', 'eb_database_upgrade_log')
ORDER BY TABLE_NAME;

SELECT 'PRECHECK_OK' AS precheck_result;
