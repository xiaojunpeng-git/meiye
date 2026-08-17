-- upgrade_key: 20260818-001-cashier-v3-line-refund-authority
-- 只检查，不写入。请在目标实例独立执行并保存输出。
SET NAMES utf8mb4;

SELECT DATABASE() AS current_database;
SELECT COUNT(*) AS lifecycle_operation_rows
FROM `eb_cashier_v3_order_lifecycle_operation`;
SELECT COUNT(*) AS refund_line_table_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_order_lifecycle_refund_line';
