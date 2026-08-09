-- upgrade_key: 20260730-014-mobile-customer-unified-query-command-receipt
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_mobile_customer_unified_query_command_receipt` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `employee_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `account_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
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
  UNIQUE KEY `uk_owner_action_idempotency` (`tenant_id`,`employee_id`,`account_id`,`action`,`idempotency_key`),
  KEY `idx_owner_time` (`tenant_id`,`employee_id`,`account_id`,`created_at`,`id`),
  KEY `idx_business_no` (`tenant_id`,`business_no`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='mobile customer unified query command immutable receipt';
