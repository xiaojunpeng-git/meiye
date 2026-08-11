-- upgrade_key: 20260811-001-cashier-v3-card-operation-entitlement-credit-v1
-- MySQL 5.6 compatible. Positive settlement authority for card/project upgrade credit.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_card_operation_settlement` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `settlement_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `operation_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sales_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sales_order_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_card_holder_id` bigint(20) unsigned NOT NULL,
  `source_legacy_order_id` bigint(20) unsigned NOT NULL,
  `source_card_no_snapshot` varchar(128) NOT NULL DEFAULT '',
  `source_order_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `source_detail_ids_json` mediumtext NOT NULL,
  `target_card_holder_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `target_legacy_order_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `target_entitlement_detail_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `entitlement_credit_cents` bigint(20) unsigned NOT NULL,
  `cash_delta_cents` bigint(20) unsigned NOT NULL,
  `settlement_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'settled',
  `reversed_by_operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `settled_at` bigint(20) unsigned NOT NULL,
  `reversed_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  `recorded_at` bigint(20) unsigned NOT NULL,
  `current_version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_settlement_id` (`settlement_id`),
  UNIQUE KEY `uk_tenant_operation` (`tenant_id`,`operation_id`),
  UNIQUE KEY `uk_tenant_checkout` (`tenant_id`,`checkout_request_id`),
  UNIQUE KEY `uk_tenant_sales_order` (`tenant_id`,`sales_order_id`),
  KEY `idx_source_holder` (`tenant_id`,`source_card_holder_id`,`settled_at`,`id`),
  KEY `idx_target_order` (`tenant_id`,`target_legacy_order_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 card operation entitlement-credit settlement authority';

SELECT 'APPLY_OK' AS apply_result;
