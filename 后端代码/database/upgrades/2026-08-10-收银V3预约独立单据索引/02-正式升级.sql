-- upgrade_key: 20260810-005-cashier-v3-reservation-independent-document-index-v1
-- MySQL 5.6 compatible. Preserve data; replace only the obsolete unique index.
SET NAMES utf8mb4;

SET @reservation_index_db := DATABASE();
SET @reservation_unique_index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @reservation_index_db
    AND TABLE_NAME = 'eb_cashier_v3_reservation'
    AND INDEX_NAME = 'uk_tenant_service_order'
);
SET @reservation_drop_unique_sql := IF(
  @reservation_unique_index_exists > 0,
  'ALTER TABLE `eb_cashier_v3_reservation` DROP INDEX `uk_tenant_service_order`',
  'SELECT ''uk_tenant_service_order already absent'' AS migration_note'
);
PREPARE reservation_drop_unique_stmt FROM @reservation_drop_unique_sql;
EXECUTE reservation_drop_unique_stmt;
DEALLOCATE PREPARE reservation_drop_unique_stmt;

SET @reservation_lookup_index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @reservation_index_db
    AND TABLE_NAME = 'eb_cashier_v3_reservation'
    AND INDEX_NAME = 'idx_tenant_service_order'
);
SET @reservation_add_lookup_sql := IF(
  @reservation_lookup_index_exists = 0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD KEY `idx_tenant_service_order` (`tenant_id`,`service_order_id`,`id`)',
  'SELECT ''idx_tenant_service_order already exists'' AS migration_note'
);
PREPARE reservation_add_lookup_stmt FROM @reservation_add_lookup_sql;
EXECUTE reservation_add_lookup_stmt;
DEALLOCATE PREPARE reservation_add_lookup_stmt;

SELECT 'APPLY_OK' AS apply_result;
