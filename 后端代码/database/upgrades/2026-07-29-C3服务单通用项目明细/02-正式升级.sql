-- upgrade_key: 20260729-006-c3-generic-service-order-line
-- MySQL 5.6.51 compatible. Run 01 first and 03 afterwards.
SET NAMES utf8mb4;
SET @c3gl_db := DATABASE();

SELECT COUNT(*) INTO @c3gl_has_source_type FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c3gl_db AND TABLE_NAME='eb_cashier_v3_service_order_line' AND COLUMN_NAME='source_type';
SET @c3gl_sql := IF(@c3gl_has_source_type=0,
  'ALTER TABLE `eb_cashier_v3_service_order_line` ADD COLUMN `source_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''ENTITLEMENT'' AFTER `line_key`',
  'SELECT ''source_type_exists'' AS migration_step');
PREPARE c3gl_stmt FROM @c3gl_sql; EXECUTE c3gl_stmt; DEALLOCATE PREPARE c3gl_stmt;

SELECT COUNT(*) INTO @c3gl_has_source_id FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c3gl_db AND TABLE_NAME='eb_cashier_v3_service_order_line' AND COLUMN_NAME='source_id';
SET @c3gl_sql := IF(@c3gl_has_source_id=0,
  'ALTER TABLE `eb_cashier_v3_service_order_line` ADD COLUMN `source_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `source_type`',
  'SELECT ''source_id_exists'' AS migration_step');
PREPARE c3gl_stmt FROM @c3gl_sql; EXECUTE c3gl_stmt; DEALLOCATE PREPARE c3gl_stmt;

SELECT COUNT(*) INTO @c3gl_has_source_version FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c3gl_db AND TABLE_NAME='eb_cashier_v3_service_order_line' AND COLUMN_NAME='source_version_snapshot';
SET @c3gl_sql := IF(@c3gl_has_source_version=0,
  'ALTER TABLE `eb_cashier_v3_service_order_line` ADD COLUMN `source_version_snapshot` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `source_id`',
  'SELECT ''source_version_snapshot_exists'' AS migration_step');
PREPARE c3gl_stmt FROM @c3gl_sql; EXECUTE c3gl_stmt; DEALLOCATE PREPARE c3gl_stmt;

SELECT COUNT(*) INTO @c3gl_has_hang_line FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c3gl_db AND TABLE_NAME='eb_cashier_v3_service_order_line' AND COLUMN_NAME='hang_line_id';
SET @c3gl_sql := IF(@c3gl_has_hang_line=0,
  'ALTER TABLE `eb_cashier_v3_service_order_line` ADD COLUMN `hang_line_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `source_version_snapshot`',
  'SELECT ''hang_line_id_exists'' AS migration_step');
PREPARE c3gl_stmt FROM @c3gl_sql; EXECUTE c3gl_stmt; DEALLOCATE PREPARE c3gl_stmt;

SELECT COUNT(*) INTO @c3gl_has_quantity FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c3gl_db AND TABLE_NAME='eb_cashier_v3_service_order_line' AND COLUMN_NAME='service_quantity';
SET @c3gl_sql := IF(@c3gl_has_quantity=0,
  'ALTER TABLE `eb_cashier_v3_service_order_line` ADD COLUMN `service_quantity` int(11) unsigned NOT NULL DEFAULT ''0'' AFTER `hang_line_id`',
  'SELECT ''service_quantity_exists'' AS migration_step');
PREPARE c3gl_stmt FROM @c3gl_sql; EXECUTE c3gl_stmt; DEALLOCATE PREPARE c3gl_stmt;

SELECT COUNT(*) INTO @c3gl_has_fingerprint FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c3gl_db AND TABLE_NAME='eb_cashier_v3_service_order_line' AND COLUMN_NAME='authority_fingerprint';
SET @c3gl_sql := IF(@c3gl_has_fingerprint=0,
  'ALTER TABLE `eb_cashier_v3_service_order_line` ADD COLUMN `authority_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `service_quantity`',
  'SELECT ''authority_fingerprint_exists'' AS migration_step');
PREPARE c3gl_stmt FROM @c3gl_sql; EXECUTE c3gl_stmt; DEALLOCATE PREPARE c3gl_stmt;

SELECT COUNT(*) INTO @c3gl_has_source_index FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c3gl_db AND TABLE_NAME='eb_cashier_v3_service_order_line'
  AND INDEX_NAME='idx_tenant_line_source';
SET @c3gl_sql := IF(@c3gl_has_source_index=0,
  'ALTER TABLE `eb_cashier_v3_service_order_line` ADD KEY `idx_tenant_line_source` (`tenant_id`,`source_type`,`source_id`,`service_order_id`,`id`,`status`)',
  'SELECT ''idx_tenant_line_source_exists'' AS migration_step');
PREPARE c3gl_stmt FROM @c3gl_sql; EXECUTE c3gl_stmt; DEALLOCATE PREPARE c3gl_stmt;

SELECT COUNT(*) INTO @c3gl_has_entitlement_index FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c3gl_db AND TABLE_NAME='eb_cashier_v3_service_order_line'
  AND INDEX_NAME='idx_tenant_entitlement_source';
SET @c3gl_sql := IF(@c3gl_has_entitlement_index=0,
  'ALTER TABLE `eb_cashier_v3_service_order_line` ADD KEY `idx_tenant_entitlement_source` (`tenant_id`,`source_type`,`entitlement_source_detail_id`,`service_order_id`,`id`,`status`)',
  'SELECT ''idx_tenant_entitlement_source_exists'' AS migration_step');
PREPARE c3gl_stmt FROM @c3gl_sql; EXECUTE c3gl_stmt; DEALLOCATE PREPARE c3gl_stmt;

SELECT 'APPLY_OK' AS apply_result;

