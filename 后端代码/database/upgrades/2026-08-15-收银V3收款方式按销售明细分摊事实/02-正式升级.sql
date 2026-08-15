-- upgrade_key: 20260815-004-cashier-v3-payment-sale-allocation-fact
-- MySQL 5.6 compatible, repeatable and additive only.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_payment_sale_allocation_fact` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `allocation_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `natural_key` varchar(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `contract_version` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fact_version` int(10) unsigned NOT NULL DEFAULT '1',
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'effective',
  `fact_direction` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'forward',
  `reversal_of` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `order_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `payment_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payment_method` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payment_amount_cents` bigint(20) NOT NULL,
  `sale_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sale_amount_cents` bigint(20) NOT NULL,
  `debt_amount_cents` bigint(20) NOT NULL DEFAULT '0',
  `allocation_base_amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `amount_cents` bigint(20) NOT NULL,
  `business_date` date NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `add_time` bigint(20) unsigned NOT NULL,
  `update_time` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_allocation_fact` (`tenant_id`,`allocation_fact_id`),
  UNIQUE KEY `uk_tenant_natural` (`tenant_id`,`natural_key`),
  KEY `idx_scope_sale_method` (`tenant_id`,`store_id`,`sale_fact_id`,`payment_method`,`status`,`id`),
  KEY `idx_payment_line` (`tenant_id`,`payment_fact_id`,`source_line_id`,`status`,`id`),
  KEY `idx_order_line` (`tenant_id`,`order_id`,`source_line_id`,`status`,`id`),
  KEY `idx_reversal` (`tenant_id`,`reversal_of`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable payment allocation by cashier V3 sale fact';

SELECT 'APPLY_OK' AS apply_result;
