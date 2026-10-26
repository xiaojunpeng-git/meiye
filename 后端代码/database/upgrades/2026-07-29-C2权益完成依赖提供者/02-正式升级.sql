-- upgrade_key: 20260729-003-c2-entitlement-provider-dependencies
-- MySQL 5.6.51 compatible. Run 01 first and 03 after this script.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_entitlement_debt_guard` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `origin_order_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `current_version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `last_action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_origin_order` (`tenant_id`,`origin_order_id`),
  KEY `idx_origin_order` (`origin_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='C2 entitlement debt concurrency guard';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_entitlement_debt_guard_mutation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `mutation_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `origin_order_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `guard_version_before` bigint(20) unsigned NOT NULL DEFAULT '1',
  `guard_version_after` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_mutation_key` (`tenant_id`,`mutation_key`),
  KEY `idx_tenant_order_version` (`tenant_id`,`origin_order_id`,`guard_version_after`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Idempotent debt guard version advances';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_staff_profile_version` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `staff_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `employee_id_snapshot` bigint(20) unsigned NOT NULL DEFAULT '0',
  `store_id_snapshot` bigint(20) unsigned NOT NULL DEFAULT '0',
  `profile_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `current_version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `last_action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_staff` (`tenant_id`,`staff_id`),
  KEY `idx_tenant_store_staff` (`tenant_id`,`store_id_snapshot`,`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='C2 locked staff authority fingerprint versions';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_entitlement_occupation_version` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `source_kind` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `store_id_snapshot` bigint(20) unsigned NOT NULL DEFAULT '0',
  `entitlement_source_detail_id_snapshot` bigint(20) unsigned NOT NULL DEFAULT '0',
  `authority_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `current_version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `last_action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_kind_source` (`tenant_id`,`source_kind`,`source_id`),
  KEY `idx_tenant_detail_kind_source` (`tenant_id`,`entitlement_source_detail_id_snapshot`,`source_kind`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='C2 complete occupation contributor versions';

SELECT 'APPLY_OK' AS apply_result;
