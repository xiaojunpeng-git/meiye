-- upgrade_key: 20260729-018-cashier-v3-entitlement-reservation-read-index-v1
-- Run 01 first. This DDL is replay-safe after a MySQL implicit commit.
SET NAMES utf8mb4;

SELECT COUNT(*) INTO @v3r_named_index_exists
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_reservation_order'
  AND INDEX_NAME='idx_c2_entitlement_cart_info';
SELECT COUNT(*) INTO @v3r_equivalent_index_count
FROM (
  SELECT INDEX_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_reservation_order'
    AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE' AND SUB_PART IS NULL
  GROUP BY INDEX_NAME
  HAVING COUNT(*)=2
    AND SUM(CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME)='1:cart_info_id')=1
    AND SUM(CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME)='2:id')=1
) v3r_equivalent_indexes;

SET @v3r_apply_sql := IF(
  @v3r_named_index_exists=0 AND @v3r_equivalent_index_count=0,
  'ALTER TABLE `eb_store_reservation_order` ADD KEY `idx_c2_entitlement_cart_info` (`cart_info_id`,`id`)',
  'SELECT ''equivalent cart_info_id,id index already exists'' AS apply_note'
);
PREPARE v3r_apply_stmt FROM @v3r_apply_sql;
EXECUTE v3r_apply_stmt;
DEALLOCATE PREPARE v3r_apply_stmt;

SELECT 'APPLY_OK' AS apply_result;
