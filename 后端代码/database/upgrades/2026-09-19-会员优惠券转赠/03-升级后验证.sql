-- upgrade_key: 20260919-001-member-coupon-transfer
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT
  CASE WHEN COUNT(*) = 1 THEN 'PASS' ELSE 'FAIL' END AS check_result,
  MAX(COLUMN_TYPE) AS column_type,
  MAX(COLUMN_DEFAULT) AS default_value
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'eb_store_coupon_issue'
  AND COLUMN_NAME = 'allow_transfer'
  AND IS_NULLABLE = 'NO';

SELECT
  CASE WHEN COUNT(*) = 1 AND MAX(ENGINE) = 'InnoDB' THEN 'PASS' ELSE 'FAIL' END AS check_result,
  MAX(ENGINE) AS engine
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'eb_store_coupon_transfer';

SELECT
  CASE WHEN COUNT(DISTINCT INDEX_NAME) = 3 THEN 'PASS' ELSE 'FAIL' END AS check_result,
  GROUP_CONCAT(DISTINCT INDEX_NAME ORDER BY INDEX_NAME) AS unique_indexes
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'eb_store_coupon_transfer'
  AND NON_UNIQUE = 0
  AND INDEX_NAME IN ('uk_transfer_no', 'uk_request_id', 'uk_coupon_user_id');

SELECT
  CASE WHEN COUNT(*) = 1 THEN 'PASS' ELSE 'FAIL' END AS check_result,
  MAX(executed_at) AS executed_at
FROM `eb_database_upgrade_log`
WHERE `upgrade_key` = '20260919-001-member-coupon-transfer';
