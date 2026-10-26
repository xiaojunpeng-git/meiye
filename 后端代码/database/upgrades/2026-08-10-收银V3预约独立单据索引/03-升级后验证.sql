-- upgrade_key: 20260810-005-cashier-v3-reservation-independent-document-index-v1
-- MySQL 5.6 compatible postflight. Read-only.
SET NAMES utf8mb4;

SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_reservation'
  AND INDEX_NAME IN ('uk_tenant_service_order', 'idx_tenant_service_order')
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT COUNT(*) AS obsolete_unique_index_count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_reservation'
  AND INDEX_NAME = 'uk_tenant_service_order';

SELECT COUNT(*) AS lookup_index_column_count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_reservation'
  AND INDEX_NAME = 'idx_tenant_service_order';

SELECT 'APPLY_OK' AS apply_result;
