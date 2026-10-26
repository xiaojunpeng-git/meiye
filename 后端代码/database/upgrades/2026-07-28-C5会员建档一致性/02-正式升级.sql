-- upgrade_key: 20260728-002-cashier-v3-member-consistency
-- MySQL 5.6 compatible: no JSON, generated columns, CTE, window functions or CHECK.
-- 01 must pass first. CREATE IF NOT EXISTS is only for safe replay, not schema repair.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_member_phone_lock` (
  `phone` varchar(15) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'normalized mobile, one row per lock resource',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 member phone lock';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_member_number_sequence` (
  `sequence_key` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `current_value` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`sequence_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 member number sequence';

INSERT INTO `eb_cashier_v3_member_number_sequence`
  (`sequence_key`,`current_value`,`created_at`,`updated_at`)
VALUES
  ('member_bar_code',99999999,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `sequence_key`=VALUES(`sequence_key`);

CREATE TABLE IF NOT EXISTS `eb_member_exclusive_service` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int(11) unsigned NOT NULL DEFAULT '0',
  `staff_id` int(11) unsigned NOT NULL DEFAULT '0',
  `employee_id` int(11) unsigned NOT NULL DEFAULT '0',
  `store_id` int(11) unsigned NOT NULL DEFAULT '0',
  `staff_name` varchar(64) NOT NULL DEFAULT '',
  `store_name` varchar(100) NOT NULL DEFAULT '',
  `source_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_business_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_business_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reason` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1' COMMENT '1 active, 0 cleared',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `bound_at` int(11) unsigned NOT NULL DEFAULT '0',
  `operator_id` int(11) unsigned NOT NULL DEFAULT '0',
  `operator_name` varchar(64) NOT NULL DEFAULT '',
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_id` (`member_id`),
  KEY `idx_staff_status` (`staff_id`,`status`),
  KEY `idx_employee_status` (`employee_id`,`status`),
  KEY `idx_store_status` (`store_id`,`status`),
  KEY `idx_bound_at` (`bound_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='current exclusive service person per member';

CREATE TABLE IF NOT EXISTS `eb_member_exclusive_service_change` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `change_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `member_id` int(11) unsigned NOT NULL DEFAULT '0',
  `previous_staff_id` int(11) unsigned NOT NULL DEFAULT '0',
  `previous_employee_id` int(11) unsigned NOT NULL DEFAULT '0',
  `previous_store_id` int(11) unsigned NOT NULL DEFAULT '0',
  `previous_staff_name` varchar(64) NOT NULL DEFAULT '',
  `previous_store_name` varchar(100) NOT NULL DEFAULT '',
  `current_staff_id` int(11) unsigned NOT NULL DEFAULT '0',
  `current_employee_id` int(11) unsigned NOT NULL DEFAULT '0',
  `current_store_id` int(11) unsigned NOT NULL DEFAULT '0',
  `current_staff_name` varchar(64) NOT NULL DEFAULT '',
  `current_store_name` varchar(100) NOT NULL DEFAULT '',
  `source_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_business_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_business_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reason` varchar(255) NOT NULL DEFAULT '',
  `operator_id` int(11) unsigned NOT NULL DEFAULT '0',
  `operator_name` varchar(64) NOT NULL DEFAULT '',
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `occurred_at` int(11) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_change_key` (`change_key`),
  KEY `idx_member_time` (`member_id`,`occurred_at`,`id`),
  KEY `idx_previous_staff_time` (`previous_staff_id`,`occurred_at`),
  KEY `idx_current_staff_time` (`current_staff_id`,`occurred_at`),
  KEY `idx_source_business` (`source_business_type`,`source_business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='exclusive service person append-only change history';

SELECT COUNT(*) INTO @c5_bar_index_exists
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_user'
  AND INDEX_NAME='idx_bar_code';

SET @c5_bar_index_sql := IF(
  @c5_bar_index_exists = 0,
  'ALTER TABLE `eb_user` ADD KEY `idx_bar_code` (`bar_code`)',
  'SELECT ''idx_bar_code already exists'' AS apply_note'
);
PREPARE c5_bar_stmt FROM @c5_bar_index_sql;
EXECUTE c5_bar_stmt;
DEALLOCATE PREPARE c5_bar_stmt;

SELECT 'APPLY_OK' AS apply_result;
