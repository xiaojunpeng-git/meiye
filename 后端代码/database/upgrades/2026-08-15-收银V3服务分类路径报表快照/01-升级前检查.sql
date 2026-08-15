-- upgrade_key: 20260815-007-cashier-v3-service-category-path-report-snapshot
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_entitlement_service_fact';
