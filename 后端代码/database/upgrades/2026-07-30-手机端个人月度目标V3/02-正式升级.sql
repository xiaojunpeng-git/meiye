-- upgrade_key: 20260730-004-mobile-personal-monthly-target-v1
-- MySQL 5.6 compatible. No legacy store_target or reporting tables are changed.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_mobile_personal_monthly_target` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `target_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `employee_id` bigint unsigned NOT NULL,
 `staff_id` bigint unsigned NOT NULL,
 `store_id` bigint unsigned NOT NULL,
 `organization_id` bigint unsigned NOT NULL,
 `month_key` char(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `name` varchar(96) NOT NULL,
 `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE',
 `revision` bigint unsigned NOT NULL DEFAULT 1,
 `created_by_employee_id` bigint unsigned NOT NULL,
 `created_at` int unsigned NOT NULL DEFAULT 0,
 `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`),
 UNIQUE KEY `uk_target_id` (`target_id`),
 UNIQUE KEY `uk_employee_staff_store_month` (`employee_id`,`staff_id`,`store_id`,`month_key`),
 KEY `idx_store_month_state` (`store_id`,`month_key`,`state`),
 KEY `idx_employee_month` (`employee_id`,`month_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_mobile_personal_monthly_target_line` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `target_id` bigint unsigned NOT NULL,
 `metric_code` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `unit_code` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `target_value` bigint unsigned NOT NULL DEFAULT 0,
 `metric_version` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'v1',
 `created_at` int unsigned NOT NULL DEFAULT 0,
 `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`),
 UNIQUE KEY `uk_target_metric` (`target_id`,`metric_code`),
 KEY `idx_metric_target` (`metric_code`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_mobile_personal_monthly_target_command` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `employee_id` bigint unsigned NOT NULL,
 `command_code` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `idempotency_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `target_id` bigint unsigned NOT NULL DEFAULT 0,
 `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'SUCCEEDED',
 `response_payload` text NOT NULL,
 `created_at` int unsigned NOT NULL DEFAULT 0,
 `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`),
 UNIQUE KEY `uk_employee_command_key` (`employee_id`,`command_code`,`idempotency_key_hash`),
 KEY `idx_target_time` (`target_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_mobile_personal_monthly_target_audit` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `target_id` bigint unsigned NOT NULL,
 `employee_id` bigint unsigned NOT NULL,
 `actor_employee_id` bigint unsigned NOT NULL,
 `action` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `revision` bigint unsigned NOT NULL,
 `snapshot` text NOT NULL,
 `request_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 `occurred_at` int unsigned NOT NULL DEFAULT 0,
 `recorded_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`),
 KEY `idx_target_revision` (`target_id`,`revision`),
 KEY `idx_employee_time` (`employee_id`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- This record is intentionally last so failed DDL is never marked executed.
INSERT INTO `eb_database_upgrade_log` (`upgrade_key`) VALUES ('20260730-004-mobile-personal-monthly-target-v1');
