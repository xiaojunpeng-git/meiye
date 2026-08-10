-- upgrade_key: 20260810-003-cashier-v3-reservation-lifecycle-v1
-- MySQL 5.6 compatible preflight. Read-only.
SET NAMES utf8mb4;

SELECT DATABASE() AS current_database;

SELECT TABLE_NAME, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_reservation',
    'eb_cashier_v3_reservation_line',
    'eb_cashier_v3_service_order'
  )
ORDER BY TABLE_NAME;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_reservation_line'
  AND COLUMN_NAME IN ('project_id', 'sku_id', 'service_duration_minutes')
ORDER BY COLUMN_NAME;

SELECT status, COUNT(*) AS row_count
FROM eb_cashier_v3_reservation
GROUP BY status
ORDER BY status;
