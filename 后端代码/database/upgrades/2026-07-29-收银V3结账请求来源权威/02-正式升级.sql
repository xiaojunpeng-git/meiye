-- upgrade_key: 20260729-006-cashier-v3-checkout-source-authority
-- MySQL 5.6 compatible. Run 01 before and 03 after this script.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_checkout_source_reference` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `bound_request_version` bigint(20) unsigned NOT NULL DEFAULT '1' COMMENT 'request version whose CAS replaced this source set',
  `source_kind` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_version` bigint(20) unsigned NOT NULL DEFAULT '1' COMMENT 'authoritative source version verified under Gateway locks',
  `source_role` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `add_time` int(11) unsigned NOT NULL DEFAULT '0',
  `update_time` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request_source` (`request_id`,`source_kind`,`source_id`),
  KEY `idx_scope_request` (`tenant_id`,`store_id`,`request_id`,`id`),
  KEY `idx_source_reverse` (`tenant_id`,`store_id`,`source_kind`,`source_id`,`request_id`),
  KEY `idx_request_role` (`request_id`,`source_role`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='server-verified current source bindings for eventless checkout requests';

SELECT 'APPLY_OK' AS apply_result;
