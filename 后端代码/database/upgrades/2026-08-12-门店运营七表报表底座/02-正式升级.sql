-- upgrade_key: 20260812-001-store-operations-seven-reports-foundation
-- MySQL 5.6 compatible and re-runnable. No existing fact is altered.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_category_config` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `category_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `category_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `category_parent_id_snapshot` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `category_parent_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `category_path_snapshot` varchar(512) NOT NULL DEFAULT '',
  `partner_name` varchar(128) NOT NULL DEFAULT '',
  `enabled` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_by` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_by_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `updated_by` bigint(20) unsigned NOT NULL DEFAULT '0',
  `updated_by_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_category` (`tenant_id`,`category_id`),
  KEY `idx_tenant_enabled_name` (`tenant_id`,`enabled`,`category_name_snapshot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='store operations report partner category configuration';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_category_config_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `category_config_id` bigint(20) unsigned NOT NULL,
  `category_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `before_snapshot_json` mediumtext NOT NULL,
  `after_snapshot_json` mediumtext NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `occurred_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_category_time` (`tenant_id`,`category_id`,`occurred_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable partner category configuration audit';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_sale_dimension_fact` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sale_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_date` date NOT NULL,
  `item_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `item_name_snapshot` varchar(255) NOT NULL DEFAULT '',
  `product_type_snapshot` varchar(64) NOT NULL DEFAULT '',
  `category_id_snapshot` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `category_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `category_parent_id_snapshot` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `category_parent_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `category_path_snapshot` varchar(512) NOT NULL DEFAULT '',
  `partner_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `is_experience` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sale_fact` (`tenant_id`,`sale_fact_id`),
  KEY `idx_scope_date_category` (`tenant_id`,`store_id`,`business_date`,`category_id_snapshot`,`id`),
  KEY `idx_order_line` (`tenant_id`,`order_id`,`source_line_id`,`id`),
  KEY `idx_partner_experience` (`tenant_id`,`partner_name_snapshot`,`is_experience`,`business_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='store operations immutable sale dimension snapshots';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_annotation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `report_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_fact_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `source_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `field_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `value_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'text',
  `field_value` text NOT NULL,
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_by` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_by_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `updated_by` bigint(20) unsigned NOT NULL DEFAULT '0',
  `updated_by_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_annotation_subject_field` (`tenant_id`,`report_code`,`subject_type`,`subject_key`,`field_key`),
  KEY `idx_annotation_scope` (`tenant_id`,`store_id`,`report_code`,`updated_at`,`id`),
  KEY `idx_annotation_source` (`tenant_id`,`source_fact_id`,`source_order_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='controlled store operations report annotations';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_annotation_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `annotation_id` bigint(20) unsigned NOT NULL,
  `report_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `field_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `before_value` text NOT NULL,
  `after_value` text NOT NULL,
  `before_version` bigint(20) unsigned NOT NULL DEFAULT '0',
  `after_version` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `occurred_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_annotation_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_annotation_history` (`tenant_id`,`annotation_id`,`occurred_at`,`id`),
  KEY `idx_report_subject` (`tenant_id`,`report_code`,`subject_type`,`subject_key`,`field_key`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable report annotation edit audit';

SELECT 'APPLY_OK' AS apply_result;
