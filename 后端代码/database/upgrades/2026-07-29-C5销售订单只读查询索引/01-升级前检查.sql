-- upgrade_key: 20260729-001-c5-sales-order-read-index
-- Read-only, MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @c5o1_db := DATABASE();
SET @c5o1_failures := 0;

SELECT @c5o1_db AS db_name, VERSION() AS mysql_version;

SELECT COUNT(*) INTO @c5o1_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5o1_db
  AND TABLE_NAME IN ('eb_store_order','eb_store_order_cart_info')
  AND ENGINE='InnoDB';
SET @c5o1_failures := @c5o1_failures + IF(@c5o1_table_count=2,0,1);

SELECT COUNT(*) INTO @c5o1_required_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c5o1_db AND (
  (TABLE_NAME='eb_store_order' AND COLUMN_NAME IN (
    'id','store_id','order_type','is_debt_repay','paid','is_system_del',
    'is_del','pid','pay_time','refund_status','terminal_action','order_id',
    'real_name','user_phone'
  ))
  OR (TABLE_NAME='eb_store_order_cart_info' AND COLUMN_NAME IN (
    'id','oid','cart_type','is_gift','cart_info'
  ))
);
SET @c5o1_failures := @c5o1_failures + IF(@c5o1_required_columns=19,0,1);

SELECT COUNT(*) INTO @c5o1_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5o1_db AND TABLE_NAME='eb_database_upgrade_log';
SET @c5o1_key_used := 0;
SET @c5o1_log_sql := IF(
  @c5o1_log_exists=1,
  'SELECT COUNT(*) INTO @c5o1_key_used FROM `eb_database_upgrade_log` WHERE `upgrade_key`=''20260729-001-c5-sales-order-read-index''',
  'SET @c5o1_key_used:=0'
);
PREPARE c5o1_log_stmt FROM @c5o1_log_sql;
EXECUTE c5o1_log_stmt;
DEALLOCATE PREPARE c5o1_log_stmt;
SET @c5o1_failures := @c5o1_failures
  + IF(@c5o1_log_exists=1,0,1)
  + IF(@c5o1_key_used=0,0,1);

SELECT COUNT(*) INTO @c5o1_order_named_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5o1_db AND TABLE_NAME='eb_store_order'
  AND INDEX_NAME='idx_c5_sales_order_read';
SELECT COUNT(*) INTO @c5o1_order_exact_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5o1_db AND TABLE_NAME='eb_store_order'
  AND INDEX_NAME='idx_c5_sales_order_read' AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE'
  AND SUB_PART IS NULL
  AND CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME) IN (
    '1:store_id','2:order_type','3:is_debt_repay','4:paid',
    '5:is_system_del','6:pay_time','7:id'
  );
SET @c5o1_failures := @c5o1_failures + IF(
  @c5o1_order_named_rows=0 OR (@c5o1_order_named_rows=7 AND @c5o1_order_exact_rows=7),0,1
);

SELECT COUNT(*) INTO @c5o1_cart_named_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5o1_db AND TABLE_NAME='eb_store_order_cart_info'
  AND INDEX_NAME='idx_c5_sales_cart_read';
SELECT COUNT(*) INTO @c5o1_cart_exact_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5o1_db AND TABLE_NAME='eb_store_order_cart_info'
  AND INDEX_NAME='idx_c5_sales_cart_read' AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE'
  AND SUB_PART IS NULL
  AND CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME) IN (
    '1:oid','2:cart_type','3:is_gift','4:id'
  );
SET @c5o1_failures := @c5o1_failures + IF(
  @c5o1_cart_named_rows=0 OR (@c5o1_cart_named_rows=4 AND @c5o1_cart_exact_rows=4),0,1
);

SET @c5o1_failure_codes := CONCAT_WS(',',
  IF(@c5o1_table_count=2,NULL,'C5O1_MISSING_AUTHORITY_TABLE'),
  IF(@c5o1_required_columns=19,NULL,'C5O1_REQUIRED_COLUMN_INVALID'),
  IF(@c5o1_log_exists=1,NULL,'C5O1_UPGRADE_LOG_MISSING'),
  IF(@c5o1_key_used=0,NULL,'C5O1_UPGRADE_KEY_USED'),
  IF(@c5o1_order_named_rows=0 OR (@c5o1_order_named_rows=7 AND @c5o1_order_exact_rows=7),
    NULL,'C5O1_ORDER_INDEX_INVALID'),
  IF(@c5o1_cart_named_rows=0 OR (@c5o1_cart_named_rows=4 AND @c5o1_cart_exact_rows=4),
    NULL,'C5O1_CART_INDEX_INVALID')
);

SELECT IF(@c5o1_failures=0,'PRECHECK_OK','PRECHECK_FAILED') AS precheck_result,
  @c5o1_failures AS failure_count,
  @c5o1_failure_codes AS failure_codes,
  @c5o1_order_named_rows AS order_index_rows,
  @c5o1_cart_named_rows AS cart_index_rows;

SET @c5o1_abort_sql := IF(
  @c5o1_failures=0,
  'SELECT ''PRECHECK_CONTINUE'' AS gate',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''C5-O1 sales order index precheck failed'''
);
PREPARE c5o1_abort_stmt FROM @c5o1_abort_sql;
EXECUTE c5o1_abort_stmt;
DEALLOCATE PREPARE c5o1_abort_stmt;
