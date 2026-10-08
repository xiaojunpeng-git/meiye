-- upgrade_key: 20261008-001-service-adjust-customer-group-fact
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_service_customer_group_fact` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `service_fact_id` bigint(20) unsigned NOT NULL,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `performance_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `staff_id` bigint(20) unsigned NOT NULL,
  `staff_job_position_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `position_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `position_version` int(10) unsigned NOT NULL DEFAULT '0',
  `position_update_time` bigint(20) unsigned NOT NULL DEFAULT '0',
  `performance_independent` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `allocation_group_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `capture_mode` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_performance_fact` (`tenant_id`,`performance_fact_id`),
  KEY `idx_operation_employee` (`tenant_id`,`operation_id`,`employee_id`),
  KEY `idx_service_employee` (`tenant_id`,`service_fact_id`,`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Frozen customer allocation group for service-record adjustments';

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT
  '20261008-001-service-adjust-customer-group-fact',
  '服务调整客数分组事实',
  '2026-10-08-服务调整客数分组事实/02-正式升级.sql',
  '', '', NOW(), 'codex-release',
  '新增手艺人调整客数分组事实；不回写历史服务或业绩事实'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log
  WHERE upgrade_key='20261008-001-service-adjust-customer-group-fact'
);

SELECT 'APPLY_OK' AS apply_result;
