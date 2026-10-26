-- upgrade_key: 20260813-001-cashier-v3-guide-round-fact
-- MySQL 5.6 compatible and re-runnable. No sales/payment/performance amount is changed.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_customer_guide_round_fact` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `natural_key` varchar(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `member_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `order_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `business_date` date NOT NULL,
  `guide_round_no` tinyint(1) unsigned NOT NULL,
  `guide_employee_id` bigint(20) unsigned NOT NULL,
  `guide_employee_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `guide_employee_type_snapshot` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `operator_id` bigint(20) unsigned NOT NULL,
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'effective',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_natural` (`tenant_id`,`natural_key`),
  UNIQUE KEY `uk_tenant_fact` (`tenant_id`,`fact_id`),
  KEY `idx_member_round_date` (`tenant_id`,`store_id`,`member_id`,`guide_round_no`,`business_date`,`id`),
  KEY `idx_order_line` (`tenant_id`,`order_id`,`source_line_id`,`id`),
  KEY `idx_guide_date` (`tenant_id`,`guide_employee_id`,`business_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 immutable customer guide round attribution facts';

SELECT 'APPLY_OK' AS apply_result;
