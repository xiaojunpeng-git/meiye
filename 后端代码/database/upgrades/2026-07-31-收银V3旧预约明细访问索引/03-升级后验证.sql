-- upgrade_key: 20260731-001-cashier-v3-reservation-cart-info-index
SET NAMES utf8mb4;
SET @db := DATABASE();
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
SELECT @exact_index AS exact_index_count;
SET @finish_sql := IF(@exact_index=1,'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_RESERVATION_CART_INFO_INDEX_POSTCHECK_FAILED');
PREPARE finish_stmt FROM @finish_sql; EXECUTE finish_stmt; DEALLOCATE PREPARE finish_stmt;
