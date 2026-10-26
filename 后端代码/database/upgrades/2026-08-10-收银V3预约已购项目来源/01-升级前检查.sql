-- upgrade_key: 20260810-004-cashier-v3-reservation-entitlement-source-v1
-- MySQL 5.6 compatible preflight. Read-only.
SET NAMES utf8mb4;

SELECT DATABASE() AS current_database;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_reservation_line'
  AND COLUMN_NAME IN ('project_source', 'sku_id', 'entitlement_source_detail_id')
ORDER BY COLUMN_NAME;

SELECT project_source, COUNT(*) AS line_count
FROM eb_cashier_v3_reservation_line
GROUP BY project_source
ORDER BY project_source;
