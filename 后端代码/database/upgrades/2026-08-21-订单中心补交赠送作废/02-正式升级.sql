-- upgrade_key: 20260821-001-cashier-v3-order-center-void
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_order_center_void_operation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `record_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_kind` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason_snapshot` varchar(255) NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'processing',
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_center_void_operation` (`operation_id`),
  UNIQUE KEY `uk_order_center_void_no` (`tenant_id`,`operation_no`),
  UNIQUE KEY `uk_order_center_void_record` (`tenant_id`,`record_id`),
  UNIQUE KEY `uk_order_center_void_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_order_center_void_scope` (`tenant_id`,`store_id`,`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 order center supplement and gift void operations';
SELECT 'APPLY_OK' AS apply_result;
