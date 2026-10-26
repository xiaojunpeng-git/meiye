-- upgrade_key: 20260917-005-cashier-v3-service-customer-snapshot
-- Read-only postcheck.
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'eb_cashier_v3_entitlement_service_fact'
  AND COLUMN_NAME = 'friend_counts_as_customer';

SELECT CASE WHEN COUNT(*) = 1 THEN 'POSTCHECK_OK' ELSE 'POSTCHECK_FAILURE' END AS postcheck_result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'eb_cashier_v3_entitlement_service_fact'
  AND COLUMN_NAME = 'friend_counts_as_customer'
  AND COLUMN_TYPE = 'tinyint(3) unsigned'
  AND IS_NULLABLE = 'NO'
  AND COLUMN_DEFAULT = '1';

SELECT CASE WHEN COUNT(*) = 1 THEN 'UPGRADE_LOG_OK' ELSE 'UPGRADE_LOG_FAILURE' END AS upgrade_log_result
FROM `eb_database_upgrade_log`
WHERE `upgrade_key` = '20260917-005-cashier-v3-service-customer-snapshot';
