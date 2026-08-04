-- upgrade_key: 20260731-001-cashier-v3-reservation-cart-info-index
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @failures := 0;

SELECT COUNT(*) INTO @log_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';

SET @key_used := 0;
SET @key_sql := IF(@log_ok=1,
  'SELECT COUNT(*) INTO @key_used FROM eb_database_upgrade_log WHERE upgrade_key=''20260731-001-cashier-v3-reservation-cart-info-index''',
  'SET @key_used:=0');
PREPARE key_stmt FROM @key_sql; EXECUTE key_stmt; DEALLOCATE PREPARE key_stmt;

SELECT COUNT(*) INTO @table_ok
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_store_reservation_order' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @columns_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_store_reservation_order'
  AND COLUMN_NAME IN ('id','cart_info_id');
SELECT COUNT(*) INTO @exact_index
FROM (
  SELECT INDEX_NAME, MAX(NON_UNIQUE) AS non_unique, MAX(INDEX_TYPE) AS index_type,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    SUM(SUB_PART IS NOT NULL) AS prefix_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_store_reservation_order'
  GROUP BY INDEX_NAME
) indexes
WHERE INDEX_NAME='idx_c2_entitlement_cart_info' AND non_unique=1
  AND index_type='BTREE' AND BINARY index_columns=BINARY 'cart_info_id,id' AND prefix_parts=0;
SELECT COUNT(*) INTO @other_cart_info_leading
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_store_reservation_order'
  AND SEQ_IN_INDEX=1 AND COLUMN_NAME='cart_info_id' AND INDEX_NAME<>'idx_c2_entitlement_cart_info';

SET @state_ok := IF((@key_used=0 AND @exact_index=0 AND @other_cart_info_leading=0)
  OR (@key_used=1 AND @exact_index=1 AND @other_cart_info_leading=0),1,0);
SET @failures := IF(@log_ok=1,0,1)+IF(@table_ok=1,0,1)+IF(@columns_ok=2,0,1)+IF(@state_ok=1,0,1);
SELECT @key_used AS upgrade_key_used,@table_ok AS table_ready,@columns_ok AS required_column_count,
  @exact_index AS exact_index_count,@other_cart_info_leading AS conflicting_leading_index_count,
  @failures AS precheck_failure_count;
SET @finish_sql := IF(@failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_RESERVATION_CART_INFO_INDEX_PRECHECK_FAILED');
PREPARE finish_stmt FROM @finish_sql; EXECUTE finish_stmt; DEALLOCATE PREPARE finish_stmt;
