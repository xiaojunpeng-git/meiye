-- upgrade_key: 20260901-001-reservation-v3-cross-client-lifecycle
-- MySQL 5.6 compatible. No historical rows are updated.
SET NAMES utf8mb4;
SET @reservation_v3_db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='lifecycle_generation')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `lifecycle_generation` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `reservation_no`',
  'SELECT ''lifecycle_generation exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='source_type')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `source_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `lifecycle_generation`',
  'SELECT ''source_type exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='confirmed_at')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `confirmed_at` int(11) unsigned NOT NULL DEFAULT ''0'' AFTER `status`',
  'SELECT ''confirmed_at exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='rejected_at')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `rejected_at` int(11) unsigned NOT NULL DEFAULT ''0'' AFTER `confirmed_at`',
  'SELECT ''rejected_at exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='reject_reason')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `reject_reason` varchar(255) NOT NULL DEFAULT '''' AFTER `rejected_at`',
  'SELECT ''reject_reason exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='actual_service_started_at')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `actual_service_started_at` int(11) unsigned NOT NULL DEFAULT ''0'' AFTER `reject_reason`',
  'SELECT ''actual_service_started_at exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='actual_service_ended_at')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `actual_service_ended_at` int(11) unsigned NOT NULL DEFAULT ''0'' AFTER `actual_service_started_at`',
  'SELECT ''actual_service_ended_at exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='member_deleted_at')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `member_deleted_at` int(11) unsigned NOT NULL DEFAULT ''0'' AFTER `actual_service_ended_at`',
  'SELECT ''member_deleted_at exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='reservation_form_json')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `reservation_form_json` longtext NULL AFTER `member_deleted_at`',
  'SELECT ''reservation_form_json exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='reservation_form_title_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `reservation_form_title_snapshot` varchar(128) NOT NULL DEFAULT '''' AFTER `reservation_form_json`',
  'SELECT ''reservation_form_title_snapshot exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='reservation_address_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `reservation_address_snapshot` varchar(512) NOT NULL DEFAULT '''' AFTER `reservation_form_title_snapshot`',
  'SELECT ''reservation_address_snapshot exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@reservation_v3_db AND TABLE_NAME='eb_cashier_v3_reservation' AND INDEX_NAME='idx_generation_store_status')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD KEY `idx_generation_store_status` (`tenant_id`,`lifecycle_generation`,`store_id`,`status`,`appointment_start_at`,`id`)',
  'SELECT ''idx_generation_store_status exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_reservation_entitlement_occupation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reservation_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `reservation_line_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `entitlement_source_detail_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `card_holder_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `occupied_times` int(11) unsigned NOT NULL DEFAULT '0',
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `released_at` int(11) unsigned NOT NULL DEFAULT '0',
  `consumed_at` int(11) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_reservation_line` (`tenant_id`,`reservation_id`,`reservation_line_id`),
  KEY `idx_entitlement_active` (`tenant_id`,`entitlement_source_detail_id`,`status`,`reservation_id`,`id`),
  KEY `idx_reservation_status` (`tenant_id`,`reservation_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='V3 reservation entitlement occupation; new generation only';

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT
  '20260901-001-reservation-v3-cross-client-lifecycle',
  '预约三端 V3 统一闭环',
  'database/upgrades/2026-09-01-预约三端V3闭环/02-正式升级.sql',
  '',
  'WORKTREE',
  NOW(),
  'Codex',
  '新世代预约唯一写 V3；不迁移、不回填历史预约'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20260901-001-reservation-v3-cross-client-lifecycle'
);

SELECT 'APPLY_OK' AS apply_result;
