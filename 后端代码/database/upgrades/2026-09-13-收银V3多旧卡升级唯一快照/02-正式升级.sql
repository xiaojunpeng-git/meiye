-- upgrade_key: 20260913-001-cashier-v3-multi-card-upgrade-snapshot-v1
-- MySQL 5.6.51 compatible.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_multi_card_upgrade` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `upgrade_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sales_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `sales_order_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `target_card_holder_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `target_legacy_order_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `target_sale_amount_cents` bigint(20) NOT NULL,
  `entitlement_credit_cents` bigint(20) NOT NULL,
  `excess_writeoff_cents` bigint(20) NOT NULL,
  `upgrade_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `snapshot_json` mediumtext NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_upgrade_id` (`upgrade_id`),
  UNIQUE KEY `uk_tenant_checkout` (`tenant_id`,`checkout_request_id`),
  KEY `idx_tenant_member_time` (`tenant_id`,`store_id`,`member_id`,`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 immutable multi old-card upgrade snapshot';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_multi_card_upgrade_source` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `upgrade_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `line_no` int(10) unsigned NOT NULL,
  `source_card_holder_id` bigint(20) unsigned NOT NULL,
  `source_legacy_order_id` bigint(20) unsigned NOT NULL,
  `source_card_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `source_card_no_snapshot` varchar(128) NOT NULL DEFAULT '',
  `source_remaining_value_cents` bigint(20) NOT NULL,
  `credit_cents` bigint(20) NOT NULL,
  `excess_writeoff_cents` bigint(20) NOT NULL,
  `source_snapshot_json` mediumtext NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_upgrade_line` (`tenant_id`,`upgrade_id`,`line_no`),
  UNIQUE KEY `uk_upgrade_holder` (`tenant_id`,`upgrade_id`,`source_card_holder_id`),
  KEY `idx_source_holder` (`tenant_id`,`source_card_holder_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 immutable multi old-card upgrade source snapshots';

SELECT 'APPLY_OK' AS apply_result;
