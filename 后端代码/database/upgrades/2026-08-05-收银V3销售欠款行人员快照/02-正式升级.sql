-- upgrade_key: 20260805-008-cashier-v3-sales-debt-line-personnel-authority
-- MySQL 5.6 compatible. No historical backfill: only new V3 debts receive this authority.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_debt_item_personnel_authority` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `debt_item_id` int(11) unsigned NOT NULL,
  `debt_id` int(11) unsigned NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `order_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `checkout_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `line_debt_amount_cents` bigint(20) unsigned NOT NULL,
  `salespeople_snapshot_json` mediumtext NOT NULL,
  `snapshot_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_debt_item` (`debt_item_id`),
  UNIQUE KEY `uk_debt_order_line` (`debt_id`,`order_line_id`),
  KEY `idx_scope_debt` (`tenant_id`,`store_id`,`debt_id`,`debt_item_id`),
  KEY `idx_order_line` (`tenant_id`,`order_line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 debt-line frozen salesperson authority';

SELECT 'APPLY_OK' AS apply_result;
