-- upgrade_key: 20260822-001-cashier-v3-recharge-gift-reversal-audit
-- MySQL 5.6 compatible. No historical backfill or mutation.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_recharge_gift_reversal` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reversal_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `recharge_id` bigint(20) unsigned NOT NULL,
  `gift_id` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `gift_item_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `gift_kind` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `legacy_order_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `card_holder_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `benefit_detail_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `coupon_user_ids_json` mediumtext NOT NULL,
  `restored_coupon_inventory_count` int(10) unsigned NOT NULL DEFAULT '0',
  `original_gift_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reversal_gift_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reversal_id` (`reversal_id`),
  UNIQUE KEY `uk_operation_item` (`operation_id`,`gift_item_id`),
  KEY `idx_source` (`tenant_id`,`recharge_id`,`id`),
  KEY `idx_gift_fact` (`tenant_id`,`original_gift_fact_id`,`id`),
  KEY `idx_member_time` (`tenant_id`,`store_id`,`member_id`,`occurred_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 append-only recharge gift reversal audit';

SELECT 'APPLY_OK' AS apply_result;
