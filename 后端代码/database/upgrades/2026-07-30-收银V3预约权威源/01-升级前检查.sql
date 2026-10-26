-- upgrade_key: 20260730-001-cashier-v3-reservation-authority
SET NAMES utf8mb4;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_service_order', 'eb_cashier_v3_service_order_line');

SELECT 'PRECHECK_OK' AS precheck_result;
