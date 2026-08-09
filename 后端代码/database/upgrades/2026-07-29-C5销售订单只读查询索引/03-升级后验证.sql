-- upgrade_key: 20260729-001-c5-sales-order-read-index
SET NAMES utf8mb4;
SET @c5o1_db := DATABASE();
SET @c5o1_verify_failures := 0;

SELECT COUNT(*) INTO @c5o1_order_exact_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5o1_db AND TABLE_NAME='eb_store_order'
  AND INDEX_NAME='idx_c5_sales_order_read' AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE'
  AND SUB_PART IS NULL
  AND CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME) IN (
    '1:store_id','2:order_type','3:is_debt_repay','4:paid',
    '5:is_system_del','6:pay_time','7:id'
  );
SELECT COUNT(*) INTO @c5o1_order_named_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5o1_db AND TABLE_NAME='eb_store_order'
  AND INDEX_NAME='idx_c5_sales_order_read';

SELECT COUNT(*) INTO @c5o1_cart_exact_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5o1_db AND TABLE_NAME='eb_store_order_cart_info'
  AND INDEX_NAME='idx_c5_sales_cart_read' AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE'
  AND SUB_PART IS NULL
  AND CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME) IN (
    '1:oid','2:cart_type','3:is_gift','4:id'
  );
SELECT COUNT(*) INTO @c5o1_cart_named_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5o1_db AND TABLE_NAME='eb_store_order_cart_info'
  AND INDEX_NAME='idx_c5_sales_cart_read';

SET @c5o1_verify_failures := @c5o1_verify_failures
  + IF(@c5o1_order_named_rows=7 AND @c5o1_order_exact_rows=7,0,1)
  + IF(@c5o1_cart_named_rows=4 AND @c5o1_cart_exact_rows=4,0,1);

SELECT IF(@c5o1_verify_failures=0,'POSTCHECK_OK','POSTCHECK_FAILED') AS postcheck_result,
  @c5o1_verify_failures AS failure_count,
  'idx_c5_sales_order_read' AS order_index,
  'idx_c5_sales_cart_read' AS cart_index;

SET @c5o1_abort_sql := IF(
  @c5o1_verify_failures=0,
  'SELECT ''POSTCHECK_CONTINUE'' AS gate',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''C5-O1 sales order index postcheck failed'''
);
PREPARE c5o1_abort_stmt FROM @c5o1_abort_sql;
EXECUTE c5o1_abort_stmt;
DEALLOCATE PREPARE c5o1_abort_stmt;

