-- upgrade_key: 20260730-001-cashier-v3-reservation-authority
-- MySQL 5.6.51 compatible. New V3 reservations only; no legacy data migration.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_reservation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reservation_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_path` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_name_snapshot` varchar(100) NOT NULL DEFAULT '',
  `store_id` int(11) unsigned NOT NULL DEFAULT '0',
  `store_name_snapshot` varchar(100) NOT NULL DEFAULT '',
  `member_id` int(11) unsigned NOT NULL DEFAULT '0',
  `member_name_snapshot` varchar(100) NOT NULL DEFAULT '',
  `member_phone_snapshot` varchar(32) NOT NULL DEFAULT '',
  `service_order_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `service_order_no_snapshot` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `room_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `room_name_snapshot` varchar(100) NOT NULL DEFAULT '',
  `appointment_start_at` int(11) unsigned NOT NULL DEFAULT '0',
  `appointment_end_at` int(11) unsigned NOT NULL DEFAULT '0',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING_CONFIRMATION',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `remark_snapshot` varchar(500) NOT NULL DEFAULT '',
  `creator_staff_id` int(11) unsigned NOT NULL DEFAULT '0',
  `creator_employee_id` int(11) unsigned NOT NULL DEFAULT '0',
  `creator_name_snapshot` varchar(64) NOT NULL DEFAULT '',
  `business_date` date NOT NULL,
  `occurred_at` int(11) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_reservation_id` (`tenant_id`,`reservation_id`),
  UNIQUE KEY `uk_tenant_reservation_no` (`tenant_id`,`reservation_no`),
  UNIQUE KEY `uk_tenant_service_order` (`tenant_id`,`service_order_id`),
  KEY `idx_store_schedule` (`tenant_id`,`store_id`,`appointment_start_at`,`id`),
  KEY `idx_member_schedule` (`tenant_id`,`member_id`,`appointment_start_at`,`id`),
  KEY `idx_store_status_schedule` (`tenant_id`,`store_id`,`status`,`appointment_start_at`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=900000000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='C3 authoritative reservation header';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_reservation_line` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reservation_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `service_order_line_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `line_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `project_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `project_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `project_source` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'UNPAID',
  `quantity` int(11) unsigned NOT NULL DEFAULT '1',
  `role_code` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'DETAIL',
  `service_duration_minutes` int(11) unsigned NOT NULL DEFAULT '0',
  `artisan_staff_ids_json` text NOT NULL,
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_reservation_line_key` (`tenant_id`,`reservation_id`,`line_key`),
  KEY `idx_reservation` (`tenant_id`,`reservation_id`,`id`),
  KEY `idx_project_schedule` (`tenant_id`,`project_id`,`reservation_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='C3 authoritative reservation project line';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_reservation_operation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `command_idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reservation_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `operation_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `version_before` bigint(20) unsigned NOT NULL DEFAULT '0',
  `version_after` bigint(20) unsigned NOT NULL DEFAULT '0',
  `result_json` text NOT NULL,
  `occurred_at` int(11) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_command_idem` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_reservation_time` (`tenant_id`,`reservation_id`,`occurred_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='C3 reservation append only operation';

SELECT 'APPLY_OK' AS apply_result;
