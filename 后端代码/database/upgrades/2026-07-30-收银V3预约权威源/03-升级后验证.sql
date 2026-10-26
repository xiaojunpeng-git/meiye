-- upgrade_key: 20260730-001-cashier-v3-reservation-authority
SET NAMES utf8mb4;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_reservation', 'eb_cashier_v3_reservation_line', 'eb_cashier_v3_reservation_operation')
ORDER BY TABLE_NAME;

SELECT CASE WHEN AUTO_INCREMENT >= 900000000 THEN 'RESERVATION_ID_NAMESPACE_OK' ELSE 'RESERVATION_ID_NAMESPACE_INVALID' END AS reservation_id_namespace
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_reservation';

SELECT 'POSTCHECK_OK' AS postcheck_result;
