-- upgrade_key: 20260820-002-service-record-craftsman-adjustment
SET NAMES utf8mb4;
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_performance_fact'
     AND COLUMN_NAME='project_count_half_units')=0,
  'ALTER TABLE `eb_cashier_v3_performance_fact` ADD COLUMN `project_count_half_units` bigint(20) NOT NULL DEFAULT 0 AFTER `labor_fee_amount_cents`, ADD KEY `idx_service_employee_project_count` (`tenant_id`,`source_line_id`,`employee_id`,`project_count_half_units`,`id`)',
  'SELECT ''project_count_half_units_already_present'' AS apply_result'
);
PREPARE service_adjust_stmt FROM @sql;
EXECUTE service_adjust_stmt;
DEALLOCATE PREPARE service_adjust_stmt;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_service_record_adjustment_operation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_no` varchar(64) NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `service_fact_id` bigint(20) unsigned NOT NULL,
  `service_record_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason_snapshot` varchar(255) NOT NULL,
  `before_snapshot_json` mediumtext NOT NULL,
  `after_snapshot_json` mediumtext NOT NULL,
  `allocation_total_amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operator_id` bigint(20) unsigned NOT NULL,
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'succeeded',
  `business_date` date NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operation_id` (`operation_id`),
  UNIQUE KEY `uk_tenant_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_service_time` (`tenant_id`,`service_fact_id`,`occurred_at`,`id`),
  KEY `idx_store_time` (`tenant_id`,`store_id`,`occurred_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 service record craftsman adjustment audit';

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT
  '20260820-002-service-record-craftsman-adjustment',
  '服务记录手艺人调整',
  '2026-08-20-服务记录手艺人调整/02-正式升级.sql',
  '', '', NOW(), 'codex-release',
  'service record adjustment audit and half-unit project count'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log
  WHERE upgrade_key='20260820-002-service-record-craftsman-adjustment'
);

SELECT 'APPLY_OK' AS apply_result;
