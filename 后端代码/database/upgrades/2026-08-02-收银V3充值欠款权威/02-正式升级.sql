-- upgrade_key: 20260802-001-cashier-v3-recharge-debt-authority
-- Run 01 before and 03 after this script. MySQL 5.6 compatible.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_recharge_debt_authority` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `debt_id` int(11) unsigned NOT NULL,
  `debt_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `recharge_id` bigint(20) unsigned NOT NULL,
  `recharge_order_no_snapshot` varchar(64) NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `policy_version` bigint(20) unsigned NOT NULL,
  `authority_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_debt_id` (`debt_id`),
  UNIQUE KEY `uk_tenant_recharge` (`tenant_id`,`recharge_id`),
  UNIQUE KEY `uk_tenant_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_scope_member` (`tenant_id`,`store_id`,`member_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 authority mapping for debt created by recharge';

SELECT 'APPLY_OK' AS apply_result;
