-- upgrade_key: 20260729-001-c5-sales-order-read-index
-- Run 01 first. Each statement is replay-safe after a partial MySQL DDL commit.
SET NAMES utf8mb4;

SELECT COUNT(*) INTO @c5o1_order_index_exists
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_order'
  AND INDEX_NAME='idx_c5_sales_order_read';
SET @c5o1_order_ddl := IF(
  @c5o1_order_index_exists=0,
  'ALTER TABLE `eb_store_order` ADD KEY `idx_c5_sales_order_read` (`store_id`,`order_type`,`is_debt_repay`,`paid`,`is_system_del`,`pay_time`,`id`)',
  'SELECT ''idx_c5_sales_order_read already exists'' AS apply_note'
);
PREPARE c5o1_order_stmt FROM @c5o1_order_ddl;
EXECUTE c5o1_order_stmt;
DEALLOCATE PREPARE c5o1_order_stmt;

SELECT COUNT(*) INTO @c5o1_cart_index_exists
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_order_cart_info'
  AND INDEX_NAME='idx_c5_sales_cart_read';
SET @c5o1_cart_ddl := IF(
  @c5o1_cart_index_exists=0,
  'ALTER TABLE `eb_store_order_cart_info` ADD KEY `idx_c5_sales_cart_read` (`oid`,`cart_type`,`is_gift`,`id`)',
  'SELECT ''idx_c5_sales_cart_read already exists'' AS apply_note'
);
PREPARE c5o1_cart_stmt FROM @c5o1_cart_ddl;
EXECUTE c5o1_cart_stmt;
DEALLOCATE PREPARE c5o1_cart_stmt;

SELECT 'APPLY_OK' AS apply_result;

