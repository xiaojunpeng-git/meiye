-- upgrade_key: 20260815-002-card-sale-component-category-fact
-- MySQL 5.6 compatible, repeatable and non-destructive.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_card_sale_category_allocation_fact` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `allocation_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `natural_key` varchar(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `contract_version` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fact_version` int(10) unsigned NOT NULL DEFAULT '1',
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'effective',
  `reversal_of` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sale_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `card_receipt_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `card_issue_no` int(10) unsigned NOT NULL,
  `component_product_id` bigint(20) unsigned NOT NULL,
  `category_id_snapshot` bigint(20) unsigned NOT NULL,
  `category_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `category_path_snapshot` varchar(512) NOT NULL DEFAULT '',
  `partner_name_snapshot` varchar(512) NOT NULL DEFAULT '',
  `product_type_snapshot` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'project',
  `component_count` int(10) unsigned NOT NULL DEFAULT '1',
  `configured_amount_cents` bigint(20) unsigned NOT NULL,
  `sale_amount_cents` bigint(20) NOT NULL,
  `cash_performance_amount_cents` bigint(20) NOT NULL,
  `business_date` date NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `add_time` bigint(20) unsigned NOT NULL,
  `update_time` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_natural` (`tenant_id`,`natural_key`),
  UNIQUE KEY `uk_tenant_receipt_category` (`tenant_id`,`card_receipt_id`,`category_id_snapshot`),
  KEY `idx_scope_category` (`tenant_id`,`store_id`,`business_date`,`category_id_snapshot`,`status`,`id`),
  KEY `idx_sale_fact` (`tenant_id`,`sale_fact_id`,`status`,`id`),
  KEY `idx_order_line` (`tenant_id`,`order_id`,`source_line_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable card sale component category allocations';

SELECT 'APPLY_OK' AS apply_result;
