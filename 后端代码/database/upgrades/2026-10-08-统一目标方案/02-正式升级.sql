-- upgrade_key: 20261008-001-unified-target-scheme
-- MySQL 5.6 compatible. New target records are independent of all legacy targets.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_target_scheme` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `name` varchar(120) NOT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  `version` bigint unsigned NOT NULL DEFAULT 1,
  `created_by` bigint unsigned NOT NULL,
  `updated_by` bigint unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_target_scheme_line` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `scheme_id` bigint unsigned NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `month_key` char(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `metric_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_id` bigint unsigned NOT NULL DEFAULT 0,
  `scope_category_ids_json` text NOT NULL COMMENT '分类目标保存时冻结自身及下级分类ID；非分类目标为[]',
  `target_value` bigint unsigned NOT NULL,
  `unit_code` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `metric_version` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `version` bigint unsigned NOT NULL DEFAULT 1,
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scheme_identity` (`scheme_id`,`store_id`,`month_key`,`metric_code`,`scope_type`,`scope_id`),
  KEY `idx_tenant_store_month` (`tenant_id`,`store_id`,`month_key`,`metric_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_target_effective_line` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `store_id` bigint unsigned NOT NULL,
  `month_key` char(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `metric_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_id` bigint unsigned NOT NULL DEFAULT 0,
  `scheme_id` bigint unsigned NOT NULL,
  `line_id` bigint unsigned NOT NULL,
  `activated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_effective_identity` (`tenant_id`,`store_id`,`month_key`,`metric_code`,`scope_type`,`scope_id`),
  UNIQUE KEY `uk_line` (`line_id`),
  KEY `idx_scheme` (`tenant_id`,`scheme_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_target_report_binding` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `report_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint unsigned NOT NULL COMMENT '报表引用按门店隔离，局部权限不能覆盖其他门店',
  `scheme_id` bigint unsigned NOT NULL,
  `version` bigint unsigned NOT NULL DEFAULT 1,
  `updated_by` bigint unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_report_store` (`tenant_id`,`report_code`,`store_id`),
  KEY `idx_scheme` (`tenant_id`,`scheme_id`,`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_target_scheme_command` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `actor_id` bigint unsigned NOT NULL,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `response_json` text NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_key` (`tenant_id`,`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_target_scheme_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  `scheme_id` bigint unsigned NOT NULL,
  `actor_id` bigint unsigned NOT NULL,
  `action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `before_json` text NOT NULL,
  `after_json` text NOT NULL,
  `occurred_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scheme_time` (`tenant_id`,`scheme_id`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
