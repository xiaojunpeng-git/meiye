-- upgrade_key: 20260729-014-mobile-customer-audiences
-- MySQL 5.6 compatible: no JSON, generated columns, CTE, window functions or CHECK.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_mobile_customer_audience` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_employee_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `owner_account_id` int(11) unsigned NOT NULL DEFAULT '0',
  `name` varchar(64) NOT NULL DEFAULT '',
  `rule_payload` text NOT NULL,
  `rule_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  `archived_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_owner_state_updated` (`owner_employee_id`,`state`,`updated_at`,`id`),
  KEY `idx_owner_account_state` (`owner_account_id`,`state`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='mobile dynamic customer audience rules';

CREATE TABLE IF NOT EXISTS `eb_mobile_customer_audience_receipt` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_employee_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `operation` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `request_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `audience_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `result_payload` text NOT NULL,
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_idempotency` (`owner_employee_id`,`idempotency_key`),
  KEY `idx_audience_operation` (`audience_id`,`operation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='mobile customer audience command receipts';

SELECT 'APPLY_OK' AS apply_result;
