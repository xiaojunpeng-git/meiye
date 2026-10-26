-- upgrade_key: 20260730-003-inventory-salon-usage
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_inventory_salon_usage_document` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usage_no` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `operation_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ISSUE',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_path` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `project_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `project_name_snapshot` varchar(120) NOT NULL DEFAULT '',
  `location_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `document_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'SETTLED',
  `remark` varchar(500) NOT NULL DEFAULT '',
  `business_date` date NOT NULL,
  `settled_at` int(11) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key`),
  UNIQUE KEY `uk_tenant_usage_no` (`tenant_id`,`usage_no`),
  KEY `idx_project_business_date` (`tenant_id`,`project_id`,`business_date`,`id`),
  KEY `idx_location_business_date` (`tenant_id`,`location_id`,`business_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='settled salon consumable issue and return authority';

CREATE TABLE IF NOT EXISTS `eb_inventory_salon_usage_line` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `line_no` int(10) unsigned NOT NULL DEFAULT '0',
  `source_usage_line_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `movement_fact_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `stock_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `batch_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `origin_batch_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `product_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `sku_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `sku_unique` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `quantity_scale` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `quantity_units` bigint(20) unsigned NOT NULL DEFAULT '0',
  `unit_cost_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_line` (`document_id`,`line_no`),
  UNIQUE KEY `uk_document_source_line` (`document_id`,`source_usage_line_id`),
  KEY `idx_source_return` (`source_usage_line_id`,`id`),
  KEY `idx_batch` (`batch_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='batch-level salon usage lineage';

SELECT 'APPLY_OK' AS apply_result;
