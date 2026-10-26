-- upgrade_key: 20260924-001-cashier-v3-checkout-reservation-service-link-v1
SET NAMES utf8mb4;

SELECT DATABASE() AS current_database;
SELECT COUNT(*) AS existing_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_reservation_checkout_service_link';

SELECT COUNT(*) AS reservation_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_reservation';

SELECT COUNT(*) AS service_fact_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact';
