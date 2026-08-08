-- upgrade_key: 20260805-003-inventory-v3-request-termination-transfer-reversal
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_inventory_stock_request_lifecycle_operation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `request_document_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `operation_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `previous_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `next_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `fulfilled_quantity_units_snapshot` bigint(20) unsigned NOT NULL DEFAULT '0',
  `reason` varchar(500) NOT NULL DEFAULT '',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `occurred_at` int(11) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_request_operation` (`request_document_id`,`operation_type`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable request cancellation and remaining-demand termination audit';

CREATE TABLE IF NOT EXISTS `eb_inventory_cross_transfer_reversal_operation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `transfer_document_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `previous_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reason` varchar(500) NOT NULL DEFAULT '',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `occurred_at` int(11) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key`),
  UNIQUE KEY `uk_transfer_reversal` (`transfer_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable cross-transfer reversal command receipt';

CREATE TABLE IF NOT EXISTS `eb_inventory_stock_request_fulfillment_reversal` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_document_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `request_line_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `reversal_of` bigint(20) unsigned NOT NULL DEFAULT '0',
  `transfer_reversal_operation_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `reversed_quantity_units` bigint(20) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reversal_of_fulfillment` (`reversal_of`),
  KEY `idx_request_line` (`request_document_id`,`request_line_id`,`id`),
  KEY `idx_transfer_reversal` (`transfer_reversal_operation_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable reversal facts for request fulfillment';

SELECT 'APPLY_OK' AS apply_result;

