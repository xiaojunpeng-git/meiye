-- upgrade_key: 20260803-003-inventory-v3-request-revision
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_inventory_stock_request_revision` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `revision_no` int(10) unsigned NOT NULL DEFAULT '0',
  `idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `previous_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `next_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `previous_snapshot_json` longtext NOT NULL,
  `changed_by_operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `changed_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_revision` (`document_id`,`revision_no`),
  UNIQUE KEY `uk_document_idempotency` (`document_id`,`idempotency_key`),
  KEY `idx_document_changed_at` (`document_id`,`changed_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='inventory request pre-fulfillment edit audit';

-- The release workflow records this upgrade only after 01 and 03 both pass.
-- That record includes the actual SQL checksum, fixed source commit and executor.
