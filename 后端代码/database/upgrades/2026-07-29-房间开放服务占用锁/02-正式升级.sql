-- upgrade_key: 20260729-019-room-open-service-guard
-- MySQL 5.6.51 compatible. Run 01 first and 03 afterwards.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_room_open_service_guard` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `store_id` int(11) unsigned NOT NULL DEFAULT '0',
  `room_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `slot_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `current_version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `occupation_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'FREE',
  `owner_kind` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `owner_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `occupied_by` int(11) unsigned NOT NULL DEFAULT '0',
  `released_by` int(11) unsigned NOT NULL DEFAULT '0',
  `occupied_at` int(11) unsigned NOT NULL DEFAULT '0',
  `released_at` int(11) unsigned NOT NULL DEFAULT '0',
  `last_action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_store_room` (`tenant_id`,`store_id`,`room_id`),
  UNIQUE KEY `uk_tenant_store_slot` (`tenant_id`,`store_id`,`slot_key`),
  KEY `idx_store_status_room` (`tenant_id`,`store_id`,`occupation_status`,`room_id`),
  KEY `idx_owner` (`tenant_id`,`owner_kind`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='V3 room singleton open-service occupation guard';

SELECT 'APPLY_OK' AS apply_result;
