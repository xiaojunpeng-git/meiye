-- upgrade_key: 20261018-001-store-service-name-master
-- 只读验证；所有 missing_* 结果都应为 0，duplicate_name_groups 应为 0。
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_service_name', 'eb_cashier_v3_service_name_setting', 'eb_cashier_v3_service_name_audit')
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_service_name', 'eb_cashier_v3_service_name_audit')
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
ORDER BY TABLE_NAME, INDEX_NAME;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_service_name'
  AND COLUMN_NAME IN ('store_id', 'service_name', 'sort_order', 'is_enabled', 'version')
ORDER BY ORDINAL_POSITION;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_service_name_setting'
  AND COLUMN_NAME IN ('store_id', 'is_enabled', 'version')
ORDER BY ORDINAL_POSITION;

SELECT
  3 - COUNT(*) AS missing_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_service_name', 'eb_cashier_v3_service_name_setting', 'eb_cashier_v3_service_name_audit');

SELECT
  2 - COUNT(DISTINCT CONCAT(TABLE_NAME, ':', INDEX_NAME)) AS missing_required_unique_indexes
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'eb_cashier_v3_service_name' AND INDEX_NAME = 'uk_store_service_name' AND NON_UNIQUE = 0)
    OR (TABLE_NAME = 'eb_cashier_v3_service_name_audit' AND INDEX_NAME = 'uk_store_request_token' AND NON_UNIQUE = 0));

SELECT
  5 - COUNT(*) AS missing_required_name_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_service_name'
  AND COLUMN_NAME IN ('store_id', 'service_name', 'sort_order', 'is_enabled', 'version');

SELECT
  3 - COUNT(*) AS missing_required_switch_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_service_name_setting'
  AND COLUMN_NAME IN ('store_id', 'is_enabled', 'version');

SELECT COUNT(*) AS duplicate_name_groups
FROM (
  SELECT store_id, service_name
  FROM eb_cashier_v3_service_name
  GROUP BY store_id, service_name
  HAVING COUNT(*) > 1
) duplicated;

SELECT upgrade_key, executed_at, executed_by, result_note
FROM eb_database_upgrade_log
WHERE upgrade_key = '20261018-001-store-service-name-master';
