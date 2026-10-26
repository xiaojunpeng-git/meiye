-- upgrade_key: 20260810-004-cashier-v3-reservation-entitlement-source-v1
-- MySQL 5.6 compatible. Historic reservation lines remain UNPAID with a 0 detail ID.
SET NAMES utf8mb4;

SET @reservation_entitlement_db := DATABASE();
SET @reservation_sku_column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @reservation_entitlement_db
    AND TABLE_NAME = 'eb_cashier_v3_reservation_line'
    AND COLUMN_NAME = 'sku_id'
);
SET @reservation_sku_column_sql := IF(
  @reservation_sku_column_exists = 0,
  'ALTER TABLE `eb_cashier_v3_reservation_line` ADD COLUMN `sku_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `project_id`',
  'SELECT ''sku_id already exists'' AS migration_note'
);
PREPARE reservation_sku_column_stmt FROM @reservation_sku_column_sql;
EXECUTE reservation_sku_column_stmt;
DEALLOCATE PREPARE reservation_sku_column_stmt;

SET @reservation_entitlement_column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @reservation_entitlement_db
    AND TABLE_NAME = 'eb_cashier_v3_reservation_line'
    AND COLUMN_NAME = 'entitlement_source_detail_id'
);
SET @reservation_entitlement_column_sql := IF(
  @reservation_entitlement_column_exists = 0,
  'ALTER TABLE `eb_cashier_v3_reservation_line` ADD COLUMN `entitlement_source_detail_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `project_source`',
  'SELECT ''entitlement_source_detail_id already exists'' AS migration_note'
);
PREPARE reservation_entitlement_column_stmt FROM @reservation_entitlement_column_sql;
EXECUTE reservation_entitlement_column_stmt;
DEALLOCATE PREPARE reservation_entitlement_column_stmt;

SET @reservation_entitlement_index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @reservation_entitlement_db
    AND TABLE_NAME = 'eb_cashier_v3_reservation_line'
    AND INDEX_NAME = 'idx_entitlement_source_detail'
);
SET @reservation_entitlement_index_sql := IF(
  @reservation_entitlement_index_exists = 0,
  'ALTER TABLE `eb_cashier_v3_reservation_line` ADD KEY `idx_entitlement_source_detail` (`tenant_id`,`entitlement_source_detail_id`,`reservation_id`,`id`)',
  'SELECT ''idx_entitlement_source_detail already exists'' AS migration_note'
);
PREPARE reservation_entitlement_index_stmt FROM @reservation_entitlement_index_sql;
EXECUTE reservation_entitlement_index_stmt;
DEALLOCATE PREPARE reservation_entitlement_index_stmt;

SELECT 'APPLY_OK' AS apply_result;
