-- upgrade_key: 20260805-003-inventory-v3-manual-document-reversal
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_inventory_manual_document_reversal` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_id` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `location_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reason` varchar(500) NOT NULL DEFAULT '',
  `operator_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `reversal_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PROCESSING',
  `result_snapshot` mediumtext NOT NULL,
  `business_date` date NOT NULL,
  `occurred_at` int(11) unsigned NOT NULL DEFAULT '0',
  `settled_at` int(11) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_document` (`tenant_id`,`source_type`,`source_id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_location_recorded` (`tenant_id`,`location_id`,`recorded_at`,`id`),
  KEY `idx_store_recorded` (`tenant_id`,`store_id`,`recorded_at`,`id`),
  KEY `idx_status_recorded` (`tenant_id`,`reversal_status`,`recorded_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='manual inventory document reversal receipt and audit authority';

SELECT 'APPLY_OK' AS apply_result;

