-- upgrade_key: 20260810-004-cashier-v3-reservation-entitlement-source-v1
-- MySQL 5.6 compatible postflight. Read-only.
SET NAMES utf8mb4;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_reservation_line'
  AND COLUMN_NAME IN ('sku_id', 'entitlement_source_detail_id')
ORDER BY COLUMN_NAME;

SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_reservation_line'
  AND INDEX_NAME = 'idx_entitlement_source_detail'
ORDER BY SEQ_IN_INDEX;

SELECT project_source, COUNT(*) AS line_count,
       SUM(CASE WHEN entitlement_source_detail_id > 0 THEN 1 ELSE 0 END) AS entitlement_detail_count
FROM eb_cashier_v3_reservation_line
GROUP BY project_source
ORDER BY project_source;

SELECT 'APPLY_OK' AS apply_result;
