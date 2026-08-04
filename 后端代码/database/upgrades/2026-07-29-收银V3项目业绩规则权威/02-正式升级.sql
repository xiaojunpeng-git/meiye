-- upgrade_key: 20260729-016-cashier-v3-project-performance-rule-v1
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_project_performance_rule` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `project_id` bigint(20) unsigned NOT NULL,
  `consumption_mode` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `consumption_configured_unit_amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `labor_mode` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `labor_configured_unit_amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `current_version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_project` (`tenant_id`,`project_id`),
  KEY `idx_tenant_updated` (`tenant_id`,`updated_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 versioned project performance rules';

SELECT 'APPLY_OK' AS apply_result;
