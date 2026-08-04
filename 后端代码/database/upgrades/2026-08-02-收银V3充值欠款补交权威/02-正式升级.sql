-- upgrade_key: 20260802-002-cashier-v3-recharge-debt-repayment-authority
-- This package creates empty V3-only recharge repayment authority tables.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_recharge_debt_repayment` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `repayment_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `repayment_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `debt_id` int(11) unsigned NOT NULL,
  `recharge_id` bigint(20) unsigned NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `debt_repaid_before_cents` bigint(20) unsigned NOT NULL,
  `debt_repaid_after_cents` bigint(20) unsigned NOT NULL,
  `debt_status_after` tinyint(3) unsigned NOT NULL,
  `balance_ledger_id` bigint(20) unsigned NOT NULL,
  `business_date` date NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `version` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_repayment_id` (`repayment_id`),
  UNIQUE KEY `uk_repayment_no` (`repayment_no`),
  UNIQUE KEY `uk_tenant_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_debt_time` (`debt_id`,`settled_at`,`id`),
  KEY `idx_scope_member_time` (`tenant_id`,`store_id`,`member_id`,`settled_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 recharge debt repayment authority';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_recharge_debt_repayment_payment` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `repayment_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payment_line_no` int(10) unsigned NOT NULL,
  `payment_method` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `collection_reference_snapshot` varchar(128) NOT NULL,
  `remark_snapshot` varchar(255) NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_repayment_line` (`repayment_id`,`payment_line_no`),
  KEY `idx_method` (`repayment_id`,`payment_method`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 recharge debt repayment payment authority';

SELECT 'APPLY_OK' AS apply_result;
