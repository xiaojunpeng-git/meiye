-- upgrade_key: 20260803-007-cashier-v3-direct-gift-authority
-- Run 01 before and 03 after this script. MySQL 5.6 compatible.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_direct_gift_authority` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gift_id` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `gift_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `reason_snapshot` varchar(120) NOT NULL,
  `validity_end` bigint(20) unsigned NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_gift` (`tenant_id`,`gift_id`),
  UNIQUE KEY `uk_tenant_gift_no` (`tenant_id`,`gift_no`),
  UNIQUE KEY `uk_tenant_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_scope_member` (`tenant_id`,`store_id`,`member_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 direct gift authority';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_direct_gift_item` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gift_id` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `item_no` int(10) unsigned NOT NULL,
  `item_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `gift_kind` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `catalog_product_id` bigint(20) unsigned NOT NULL,
  `catalog_product_type` int(10) NOT NULL,
  `coupon_issue_id` bigint(20) unsigned NOT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `content_name_snapshot` varchar(128) NOT NULL,
  `content_snapshot_json` longtext NOT NULL,
  `legacy_order_id` bigint(20) unsigned NOT NULL,
  `card_holder_id` bigint(20) unsigned NOT NULL,
  `benefit_detail_id` bigint(20) unsigned NOT NULL,
  `coupon_user_ids_json` longtext NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `voided_at` bigint(20) unsigned NOT NULL,
  `void_reason_snapshot` varchar(255) NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gift_item_no` (`gift_id`,`item_no`),
  UNIQUE KEY `uk_item_id` (`item_id`),
  KEY `idx_projection_holder` (`card_holder_id`),
  KEY `idx_projection_benefit` (`benefit_detail_id`),
  KEY `idx_status_time` (`status`,`settled_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 direct gift issuance items';

SELECT 'APPLY_OK' AS apply_result;
