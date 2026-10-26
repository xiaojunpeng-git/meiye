-- upgrade_key: 20260729-010-cashier-v3-checkout-resource-plan
-- MySQL 5.6 compatible. Run 01 before and 03 after this script.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_checkout_resource_plan` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `bound_request_version` bigint(20) unsigned NOT NULL DEFAULT '0',
  `contract_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `plan_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  `resource_count` int(10) unsigned NOT NULL DEFAULT '0',
  `role_count` int(10) unsigned NOT NULL DEFAULT '0',
  `resource_plan_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `invalidation_reason` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `prepared_at` int(11) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request_bound_version` (`request_id`,`bound_request_version`),
  KEY `idx_scope_request_status` (`tenant_id`,`store_id`,`request_id`,`plan_status`,`id`),
  KEY `idx_status_updated` (`plan_status`,`updated_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable server-verified checkout resource plan header';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_checkout_resource_plan_row` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `bound_request_version` bigint(20) unsigned NOT NULL DEFAULT '0',
  `resource_kind` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `resource_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `scope_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `scope_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `lock_order` int(10) unsigned NOT NULL DEFAULT '0',
  `expected_version` bigint(20) unsigned NOT NULL DEFAULT '0',
  `roles_json` mediumtext NOT NULL,
  `role_count` int(10) unsigned NOT NULL DEFAULT '0',
  `access_mode` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `provider_contract_version` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `authority_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `row_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plan_physical_resource` (`plan_id`,`resource_kind`,`resource_id`),
  UNIQUE KEY `uk_request_version_resource` (`request_id`,`bound_request_version`,`resource_kind`,`resource_id`),
  KEY `idx_plan_lock_order` (`plan_id`,`lock_order`,`resource_kind`,`resource_id`),
  KEY `idx_scope_resource` (`tenant_id`,`store_id`,`resource_kind`,`resource_id`,`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='one physical resource with canonical multi-role checkout bindings';

SELECT 'APPLY_OK' AS apply_result;
