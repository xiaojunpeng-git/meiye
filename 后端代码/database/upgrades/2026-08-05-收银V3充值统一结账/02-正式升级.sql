-- upgrade_key: 20260805-008-cashier-v3-recharge-checkout
-- MySQL 5.6 compatible. Eventless recharge checkout draft; successful recharge still writes through RechargeModule.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_recharge_checkout_request` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `workspace_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state_context_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `request_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'editing',
  `recharge_mode` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recharge_package_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `principal_cents` bigint(20) unsigned NOT NULL,
  `bonus_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `debt_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `credited_principal_cents` bigint(20) unsigned NOT NULL,
  `balance_version` bigint(20) unsigned NOT NULL,
  `salespeople_json` mediumtext NOT NULL,
  `terms_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `creation_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `last_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recharge_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `recharge_order_no` varchar(64) NOT NULL DEFAULT '',
  `business_date` date NOT NULL,
  `occurred_at` int(11) unsigned NOT NULL,
  `settled_at` int(11) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL,
  `add_time` int(11) unsigned NOT NULL,
  `update_time` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request_id` (`request_id`),
  UNIQUE KEY `uk_tenant_creation_idem` (`tenant_id`,`creation_idempotency_key`),
  KEY `idx_scope_status` (`tenant_id`,`store_id`,`operator_id`,`workspace_id`,`state_context_id`,`request_status`,`id`),
  KEY `idx_member_time` (`tenant_id`,`store_id`,`member_id`,`recorded_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 eventless recharge checkout request';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_recharge_checkout_payment_draft` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_draft_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `draft_version` bigint(20) unsigned NOT NULL,
  `payment_authority_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payment_method` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `collection_reference` varchar(128) NOT NULL DEFAULT '',
  `sort_no` int(10) unsigned NOT NULL,
  `draft_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  `add_time` int(11) unsigned NOT NULL,
  `update_time` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_draft_id` (`payment_draft_id`),
  UNIQUE KEY `uk_request_version_authority` (`request_id`,`draft_version`,`payment_authority_key`),
  KEY `idx_request_version` (`request_id`,`draft_version`,`draft_status`,`sort_no`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 eventless recharge checkout payment draft';

SELECT 'APPLY_OK' AS apply_result;
