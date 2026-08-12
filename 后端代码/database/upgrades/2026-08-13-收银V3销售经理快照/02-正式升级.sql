-- upgrade_key: 20260813-002-cashier-v3-sales-manager-fact
SET NAMES utf8mb4;
SET @sm_db := DATABASE();
SET @sm_has_column := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sm_db AND TABLE_NAME='eb_cashier_v3_workspace_line' AND COLUMN_NAME='sales_manager_selections_json');
SET @sm_sql := IF(@sm_has_column=0,
  'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `sales_manager_selections_json` MEDIUMTEXT NULL AFTER `guide_selections_json`',
  'SELECT ''COLUMN_ALREADY_PRESENT'' AS apply_result');
PREPARE sm_stmt FROM @sm_sql; EXECUTE sm_stmt; DEALLOCATE PREPARE sm_stmt;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_sales_manager_fact` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `natural_key` varchar(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `order_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_date` date NOT NULL,
  `sales_manager_employee_id` bigint(20) unsigned NOT NULL,
  `sales_manager_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `sales_manager_type_snapshot` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `operator_id` bigint(20) unsigned NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'effective',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_natural` (`tenant_id`,`natural_key`),
  UNIQUE KEY `uk_tenant_fact` (`tenant_id`,`fact_id`),
  KEY `idx_manager_date` (`tenant_id`,`sales_manager_employee_id`,`business_date`,`id`),
  KEY `idx_order_line` (`tenant_id`,`order_id`,`source_line_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 immutable sales manager attribution facts';
SELECT 'APPLY_OK' AS apply_result;
