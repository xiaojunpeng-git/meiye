-- upgrade_key: 20260729-018-cashier-v3-entitlement-reservation-read-index-v1
SET NAMES utf8mb4;
SET @v3r_db := DATABASE();
SET @v3r_failures := 0;

SELECT COUNT(*) INTO @v3r_equivalent_index_count
FROM (
  SELECT INDEX_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@v3r_db AND TABLE_NAME='eb_store_reservation_order'
    AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE' AND SUB_PART IS NULL
  GROUP BY INDEX_NAME
  HAVING COUNT(*)=2
    AND SUM(CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME)='1:cart_info_id')=1
    AND SUM(CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME)='2:id')=1
) v3r_equivalent_indexes;
SELECT COUNT(*) INTO @v3r_named_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@v3r_db AND TABLE_NAME='eb_store_reservation_order'
  AND INDEX_NAME='idx_c2_entitlement_cart_info';
SELECT COUNT(*) INTO @v3r_named_exact_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@v3r_db AND TABLE_NAME='eb_store_reservation_order'
  AND INDEX_NAME='idx_c2_entitlement_cart_info' AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE'
  AND SUB_PART IS NULL
  AND CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME) IN ('1:cart_info_id','2:id');

SET @v3r_failures := @v3r_failures
  + IF(@v3r_equivalent_index_count>=1,0,1)
  + IF(@v3r_named_rows=0 OR (@v3r_named_rows=2 AND @v3r_named_exact_rows=2),0,1);

SELECT IF(@v3r_failures=0,'POSTCHECK_OK','POSTCHECK_FAILED') AS postcheck_result,
  @v3r_failures AS failure_count,
  @v3r_equivalent_index_count AS equivalent_index_count,
  @v3r_named_rows AS named_index_rows;

SET @v3r_abort_sql := IF(
  @v3r_failures=0,
  'SELECT ''POSTCHECK_CONTINUE'' AS gate',
  'SELECT * FROM STOP_CASHIER_V3_ENTITLEMENT_RESERVATION_INDEX_POSTCHECK_FAILED'
);
PREPARE v3r_abort_stmt FROM @v3r_abort_sql;
EXECUTE v3r_abort_stmt;
DEALLOCATE PREPARE v3r_abort_stmt;
