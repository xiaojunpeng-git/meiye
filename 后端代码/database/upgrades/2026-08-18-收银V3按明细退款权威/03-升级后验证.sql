-- upgrade_key: 20260818-001-cashier-v3-line-refund-authority
SET NAMES utf8mb4;

SELECT COUNT(*) AS refund_line_table_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_order_lifecycle_refund_line';

SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_order_lifecycle_refund_line'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;
