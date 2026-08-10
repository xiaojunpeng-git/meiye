-- upgrade_key: 20260810-003-cashier-v3-reservation-lifecycle-v1
-- MySQL 5.6 compatible postflight. Read-only.
SET NAMES utf8mb4;

SELECT COUNT(*) AS sku_id_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_reservation_line'
  AND COLUMN_NAME = 'sku_id'
  AND COLUMN_TYPE = 'bigint(20) unsigned';

SELECT status, COUNT(*) AS row_count
FROM eb_cashier_v3_reservation
GROUP BY status
ORDER BY status;

SELECT
  COUNT(*) AS total_lines,
  SUM(CASE WHEN sku_id = 0 THEN 1 ELSE 0 END) AS legacy_lines_without_sku,
  SUM(CASE WHEN sku_id > 0 THEN 1 ELSE 0 END) AS lines_with_sku
FROM eb_cashier_v3_reservation_line;

SELECT 'APPLY_OK' AS apply_result;
