-- upgrade_key: 20261015-001-presale-gift-claimable-void
-- MySQL 5.6 compatible; the source row retains its original quantity and claims.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_presale_claimable_void` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `claimable_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `source_kind` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `previous_status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `previous_close_operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `remaining_quantity` bigint(20) unsigned NOT NULL,
  `reason_snapshot` varchar(500) NOT NULL DEFAULT '',
  `operator_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operation` (`operation_id`),
  UNIQUE KEY `uk_claimable` (`tenant_id`,`claimable_line_id`),
  UNIQUE KEY `uk_command` (`tenant_id`,`idempotency_key`),
  KEY `idx_store_time` (`tenant_id`,`store_id`,`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='presale and gift remaining claim entitlement void audit';

INSERT INTO `eb_database_upgrade_log`
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20261015-001-presale-gift-claimable-void',
       '预售赠送领用资格作废审计',
       '2026-10-15-预售赠送领用资格作废/02-正式升级.sql',
       '', '', NOW(), 'codex-local',
       'created claimable void audit table; no source rows changed'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20261015-001-presale-gift-claimable-void'
);
SELECT 'APPLY_OK' AS apply_result;
