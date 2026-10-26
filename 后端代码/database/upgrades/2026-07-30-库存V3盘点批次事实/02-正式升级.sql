-- upgrade_key: 20260730-001-inventory-batch-stock-count
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_inventory_stock_count_document` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `count_no` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_path` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `location_id` bigint(20) unsigned NOT NULL DEFAULT '0', `store_id` bigint(20) unsigned NOT NULL DEFAULT '0', `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `document_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CONFIRMED', `remark` varchar(500) NOT NULL DEFAULT '', `business_date` date NOT NULL,
  `confirmed_at` int(11) unsigned NOT NULL DEFAULT '0', `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key`), UNIQUE KEY `uk_tenant_count_no` (`tenant_id`,`count_no`),
  KEY `idx_scope_date` (`tenant_id`,`location_id`,`business_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='confirmed inventory batch count authority';
CREATE TABLE IF NOT EXISTS `eb_inventory_stock_count_line` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `document_id` bigint(20) unsigned NOT NULL DEFAULT '0', `line_no` int(10) unsigned NOT NULL DEFAULT '0',
  `stock_id` bigint(20) unsigned NOT NULL DEFAULT '0', `product_id` bigint(20) unsigned NOT NULL DEFAULT '0', `sku_id` bigint(20) unsigned NOT NULL DEFAULT '0', `sku_unique` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `quantity_scale` tinyint(3) unsigned NOT NULL DEFAULT '0', `book_quantity_units` bigint(20) unsigned NOT NULL DEFAULT '0', `counted_quantity_units` bigint(20) unsigned NOT NULL DEFAULT '0', `difference_quantity_units` bigint(20) NOT NULL DEFAULT '0',
  `surplus_batch_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '', `surplus_unit_cost_cents` bigint(20) unsigned NOT NULL DEFAULT '0', `surplus_manufactured_date` date NULL DEFAULT NULL, `surplus_expire_date` date NULL DEFAULT NULL,
  `created_at` int(11) unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`id`), UNIQUE KEY `uk_document_line` (`document_id`,`line_no`), KEY `idx_stock` (`stock_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='inventory count book and physical snapshot';
