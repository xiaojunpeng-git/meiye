-- upgrade_key: 20260827-001-customer-unconsumed-query-performance
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_user_card_holder', 'eb_store_order_cart_info')
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

SELECT upgrade_key, executed_at, executed_by
FROM eb_database_upgrade_log
WHERE upgrade_key = '20260827-001-customer-unconsumed-query-performance';
