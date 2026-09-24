-- upgrade_key: 20260924-001-cashier-v3-checkout-reservation-service-link-v1
-- MySQL 5.6.51 compatible. Existing reservations and service facts remain untouched.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_reservation_checkout_service_link` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reservation_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `sales_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `sales_order_no_snapshot` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `service_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `project_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `project_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `quantity` int(11) unsigned NOT NULL DEFAULT '1',
  `service_object` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `primary_craftsman_staff_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `craftsmen_snapshot_json` text NOT NULL,
  `business_date` date NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `occurred_at` int(11) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_reservation_service_fact` (`tenant_id`,`reservation_id`,`service_fact_id`),
  KEY `idx_tenant_reservation` (`tenant_id`,`reservation_id`,`id`),
  KEY `idx_tenant_sales_order` (`tenant_id`,`sales_order_id`,`reservation_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='V3 checkout actual service snapshot linked to reservation';

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT
  '20260924-001-cashier-v3-checkout-reservation-service-link-v1',
  '结账项目同步预约实际服务',
  'database/upgrades/2026-09-24-结账项目同步预约实际服务/02-正式升级.sql',
  '',
  'WORKTREE',
  NOW(),
  'Codex',
  '新增结账服务事实与预约的不可变展示关联；不回填历史数据'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20260924-001-cashier-v3-checkout-reservation-service-link-v1'
);

SELECT 'APPLY_OK' AS apply_result;
