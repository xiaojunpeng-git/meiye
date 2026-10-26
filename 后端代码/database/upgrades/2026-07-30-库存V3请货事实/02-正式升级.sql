-- upgrade_key: 20260730-002-inventory-stock-request
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_inventory_stock_request_document` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_no` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_path` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `location_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `document_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'APPLIED',
  `remark` varchar(500) NOT NULL DEFAULT '',
  `business_date` date NOT NULL,
  `applied_at` int(11) unsigned NOT NULL DEFAULT '0',
  `cancelled_at` int(11) unsigned NOT NULL DEFAULT '0',
  `cancelled_by_operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key`),
  UNIQUE KEY `uk_tenant_request_no` (`tenant_id`,`request_no`),
  KEY `idx_scope_status_date` (`tenant_id`,`location_id`,`document_status`,`business_date`,`id`),
  KEY `idx_store_status` (`tenant_id`,`store_id`,`document_status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='inventory stock request authority; creates no stock movement';

CREATE TABLE IF NOT EXISTS `eb_inventory_stock_request_line` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `line_no` int(10) unsigned NOT NULL DEFAULT '0',
  `product_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `sku_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `sku_unique` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `product_name_snapshot` varchar(120) NOT NULL DEFAULT '',
  `sku_name_snapshot` varchar(120) NOT NULL DEFAULT '',
  `product_code_snapshot` varchar(64) NOT NULL DEFAULT '',
  `barcode_snapshot` varchar(64) NOT NULL DEFAULT '',
  `stock_unit_snapshot` varchar(32) NOT NULL DEFAULT '',
  `quantity_scale` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `requested_quantity_units` bigint(20) unsigned NOT NULL DEFAULT '0',
  `reference_unit_cost_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `reference_cost_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'UNKNOWN',
  `fulfilled_transfer_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_line` (`document_id`,`line_no`),
  KEY `idx_sku` (`product_id`,`sku_id`,`id`),
  KEY `idx_fulfilled_transfer` (`fulfilled_transfer_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='inventory stock request SKU snapshots';

SELECT 'APPLY_OK' AS apply_result;
