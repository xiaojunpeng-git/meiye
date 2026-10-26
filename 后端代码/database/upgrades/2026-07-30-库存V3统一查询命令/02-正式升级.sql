-- upgrade_key: 20260730-010-inventory-v3-unified-query-command-receipt
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_inventory_unified_query_command_receipt` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `page_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PROCESSING',
  `result_json` mediumtext NOT NULL,
  `business_no` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scope_action_idempotency` (`tenant_id`,`store_id`,`operator_id`,`action`,`idempotency_key`),
  KEY `idx_scope_time` (`tenant_id`,`store_id`,`operator_id`,`created_at`,`id`),
  KEY `idx_business_no` (`tenant_id`,`business_no`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='inventory unified query command immutable receipt';
