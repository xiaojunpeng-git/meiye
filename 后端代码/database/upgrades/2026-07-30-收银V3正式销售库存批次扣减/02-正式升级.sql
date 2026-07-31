-- upgrade_key: 20260730-002-cashier-v3-sale-inventory-settlement-v1
-- MySQL 5.6.51 compatible. Run 01 before and 03 after this script.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_sale_inventory_receipt` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `plan_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `contract_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_path_snapshot` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL,
  `store_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `operator_id` bigint(20) unsigned NOT NULL,
  `checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sales_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actual_cost_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `allocation_count` int(10) unsigned NOT NULL DEFAULT '0',
  `result_snapshot` mediumtext NOT NULL,
  `business_date` date NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `add_time` bigint(20) unsigned NOT NULL,
  `update_time` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_receipt` (`tenant_id`,`receipt_id`),
  UNIQUE KEY `uk_tenant_checkout` (`tenant_id`,`checkout_request_id`),
  UNIQUE KEY `uk_tenant_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_scope_business_date` (`tenant_id`,`store_id`,`business_date`,`id`),
  KEY `idx_sales_order` (`tenant_id`,`sales_order_id`,`id`),
  KEY `idx_org_business_date` (`tenant_id`,`organization_id`,`business_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 settled ordinary-product inventory receipt';

SELECT 'APPLY_OK' AS apply_result;
