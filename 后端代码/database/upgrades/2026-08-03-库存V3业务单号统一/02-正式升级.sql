-- upgrade_key: 20260803-001-inventory-v3-business-document-numbers
-- MySQL 5.6 compatible. This migration intentionally performs no historic data backfill.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_inventory_document_sequence` (
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `document_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `business_date` date NOT NULL,
  `current_value` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`tenant_id`,`document_type`,`business_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='inventory visible document number sequence by tenant type and business date';

CREATE TABLE IF NOT EXISTS `eb_inventory_business_document_no` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_id` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `business_date` date NOT NULL,
  `document_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_source` (`tenant_id`,`source_type`,`source_id`),
  UNIQUE KEY `uk_tenant_document_no` (`tenant_id`,`document_no`),
  KEY `idx_tenant_date` (`tenant_id`,`business_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='new manual inventory inbound outbound visible document number mapping';

SELECT 'APPLY_OK' AS apply_result;
