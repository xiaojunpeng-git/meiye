-- upgrade_key: 20260730-023-cashier-v3-card-purchase-issuance-v1
-- MySQL 5.6.51 compatible; no historic data migration.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_card_purchase_receipt` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sales_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sales_order_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issue_no` int(10) unsigned NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `contract_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `catalog_product_id` bigint(20) unsigned NOT NULL,
  `catalog_sku_id` bigint(20) unsigned NOT NULL,
  `legacy_order_id` bigint(20) unsigned NOT NULL,
  `card_holder_id` bigint(20) unsigned NOT NULL,
  `base_cart_id` bigint(20) unsigned NOT NULL,
  `benefit_detail_ids_json` mediumtext NOT NULL,
  `result_snapshot_json` mediumtext NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `add_time` bigint(20) unsigned NOT NULL,
  `update_time` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_receipt` (`tenant_id`,`receipt_id`),
  UNIQUE KEY `uk_tenant_line_issue` (`tenant_id`,`sales_order_line_id`,`issue_no`),
  UNIQUE KEY `uk_tenant_holder` (`tenant_id`,`card_holder_id`),
  KEY `idx_checkout` (`tenant_id`,`checkout_request_id`,`id`),
  KEY `idx_sales_order` (`tenant_id`,`sales_order_id`,`id`),
  KEY `idx_member_time` (`tenant_id`,`store_id`,`member_id`,`settled_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 card purchase formal issuance receipt';

SELECT 'APPLY_OK' AS apply_result;
