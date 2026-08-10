-- upgrade_key: 20260810-005-cashier-v3-reservation-independent-document-index-v1
-- MySQL 5.6 compatible preflight. Read-only.
SET NAMES utf8mb4;

SELECT DATABASE() AS current_database;

SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_reservation'
  AND INDEX_NAME IN ('uk_tenant_service_order', 'idx_tenant_service_order')
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT tenant_id, service_order_id, COUNT(*) AS reservation_count
FROM eb_cashier_v3_reservation
GROUP BY tenant_id, service_order_id
HAVING COUNT(*) > 1
ORDER BY reservation_count DESC, tenant_id, service_order_id
LIMIT 20;
