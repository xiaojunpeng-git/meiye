-- upgrade_key: 20260915-001-cashier-v3-reservation-time-window-v1
-- MySQL 5.6.51 compatible.  No historical reservation rows are rewritten.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_reservation_staff_schedule` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reservation_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `staff_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `is_point_customer` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_reservation_staff` (`tenant_id`,`reservation_id`,`staff_id`),
  KEY `idx_tenant_staff_reservation` (`tenant_id`,`staff_id`,`reservation_id`,`id`),
  KEY `idx_tenant_reservation` (`tenant_id`,`reservation_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='V3 authoritative reservation staff schedule';

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT
  '20260915-001-cashier-v3-reservation-time-window-v1',
  '收银 V3 预约时段与服务前置',
  'database/upgrades/2026-09-15-预约时段与服务前置/02-正式升级.sql',
  '',
  'WORKTREE',
  NOW(),
  'Codex',
  '新增预约级手艺人安排关系；不回写历史预约'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20260915-001-cashier-v3-reservation-time-window-v1'
);

SELECT 'APPLY_OK' AS apply_result;
