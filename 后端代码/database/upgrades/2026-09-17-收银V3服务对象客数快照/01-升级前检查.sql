-- upgrade_key: 20260917-005-cashier-v3-service-customer-snapshot
-- Read-only precheck. Run against the target instance before 02.
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT DATABASE() AS target_database;

SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'eb_cashier_v3_entitlement_service_fact';

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'eb_cashier_v3_entitlement_service_fact'
  AND COLUMN_NAME IN ('service_object', 'friend_counts_as_customer')
ORDER BY ORDINAL_POSITION;

SELECT COUNT(*) AS existing_service_fact_rows
FROM `eb_cashier_v3_entitlement_service_fact`;
