-- upgrade_key: 20260916-001-cashier-v3-reservation-point-customer-service-snapshot-v1
-- MySQL 5.6.51 compatible. Existing service orders remain untouched.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_service_order_staff_assignment` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `service_order_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `staff_id` int(11) unsigned NOT NULL DEFAULT '0',
  `employee_id` int(11) unsigned NOT NULL DEFAULT '0',
  `staff_name_snapshot` varchar(64) NOT NULL DEFAULT '',
  `is_point_customer` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_service_order_staff` (`tenant_id`,`service_order_id`,`staff_id`),
  KEY `idx_tenant_staff_service_order` (`tenant_id`,`staff_id`,`service_order_id`,`id`),
  KEY `idx_tenant_service_order` (`tenant_id`,`service_order_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='V3 service order staff assignment snapshot';

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT
  '20260916-001-cashier-v3-reservation-point-customer-service-snapshot-v1',
  '收银 V3 预约点客服务单快照',
  'database/upgrades/2026-09-16-预约点客服务单快照/02-正式升级.sql',
  '',
  'WORKTREE',
  NOW(),
  'Codex',
  '预约点客人员在开始服务时写入服务单快照'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20260916-001-cashier-v3-reservation-point-customer-service-snapshot-v1'
);

SELECT 'APPLY_OK' AS apply_result;
