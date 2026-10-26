-- upgrade_key: 20260803-002-inventory-v3-excel-import
-- MySQL 5.6 compatible. No historic inventory or legacy import data is changed.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_inventory_v3_import_record` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `direction` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `file_hash` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_file_name` varchar(120) NOT NULL DEFAULT '',
  `business_type` varchar(40) NOT NULL DEFAULT '',
  `total_count` int(10) unsigned NOT NULL DEFAULT '0',
  `success_count` int(10) unsigned NOT NULL DEFAULT '0',
  `failure_count` int(10) unsigned NOT NULL DEFAULT '0',
  `document_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PROCESSING',
  `failure_message` varchar(500) NOT NULL DEFAULT '',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_store_direction_file` (`tenant_id`,`store_id`,`direction`,`file_hash`),
  KEY `idx_store_updated` (`store_id`,`updated_at`,`id`),
  KEY `idx_store_status` (`store_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='inventory v3 excel import evidence and file idempotency';

CREATE TABLE IF NOT EXISTS `eb_inventory_v3_import_error` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `record_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `excel_row` int(10) unsigned NOT NULL DEFAULT '0',
  `error_message` varchar(500) NOT NULL DEFAULT '',
  `row_snapshot` text,
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_record_row` (`record_id`,`excel_row`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='inventory v3 excel import row failures';

SELECT 'APPLY_OK' AS apply_result;
