-- upgrade_key: 20260815-004-cashier-v3-payment-sale-allocation-fact
-- Read-only precheck. Run against the target instance before 02.
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_sale_fact', 'eb_cashier_v3_payment_fact')
ORDER BY TABLE_NAME;

SELECT COUNT(*) AS allocation_table_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_payment_sale_allocation_fact';
