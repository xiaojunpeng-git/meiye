-- upgrade_key: 20260919-001-member-coupon-transfer
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT
  CASE WHEN COUNT(*) = 3 THEN 'PASS' ELSE 'FAIL' END AS check_result,
  GROUP_CONCAT(TABLE_NAME ORDER BY TABLE_NAME) AS existing_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('eb_store_coupon_issue', 'eb_store_coupon_user', 'eb_database_upgrade_log');

SELECT
  CASE WHEN COUNT(*) = 4 THEN 'PASS' ELSE 'FAIL' END AS check_result,
  GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS existing_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'eb_store_coupon_user'
  AND COLUMN_NAME IN ('id', 'cid', 'uid', 'end_time');

SELECT
  CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'ALREADY_APPLIED' END AS check_result
FROM `eb_database_upgrade_log`
WHERE `upgrade_key` = '20260919-001-member-coupon-transfer';
