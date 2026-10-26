-- upgrade_key: 20260731-005-cashier-v3-custom-card-configuration
-- Run 01 before and 03 after this script. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_custom_card_configuration` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `configuration_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `workspace_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `workspace_line_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `card_name_snapshot` varchar(64) NOT NULL,
  `validity_end_at` bigint(20) unsigned NOT NULL,
  `activate_on_purchase` tinyint(1) unsigned NOT NULL,
  `total_amount_cents` bigint(20) unsigned NOT NULL,
  `configuration_snapshot_json` mediumtext NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `resource_version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `sales_order_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `card_holder_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `settled_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  `add_time` bigint(20) unsigned NOT NULL,
  `update_time` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_configuration` (`tenant_id`,`configuration_id`),
  UNIQUE KEY `uk_tenant_command` (`tenant_id`,`created_command_idempotency_key`),
  UNIQUE KEY `uk_tenant_workspace_line` (`tenant_id`,`workspace_id`,`workspace_line_key`),
  KEY `idx_scope_status` (`tenant_id`,`store_id`,`status`,`id`),
  KEY `idx_member_status` (`tenant_id`,`store_id`,`member_id`,`status`,`id`),
  KEY `idx_checkout` (`tenant_id`,`checkout_request_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 custom card immutable configuration source';

SELECT 'APPLY_OK' AS apply_result;
