-- 原退款归属行保持不变；每次改派追加一个完整版本，空人员集也有版本头记录。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_refund_attribution_revision` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `revision_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision_no` int(10) unsigned NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `old_snapshot_json` text NOT NULL,
  `new_snapshot_json` text NOT NULL,
  `reason` varchar(255) NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_revision_id` (`revision_id`),
  UNIQUE KEY `uk_operation_version` (`tenant_id`,`operation_id`,`revision_no`),
  UNIQUE KEY `uk_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_operation_latest` (`tenant_id`,`operation_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='退款导购销售经理改派版本及审计';

-- 人员仍以明确列保存，列表、导出和后续按人员查询不依赖事件 JSON。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_refund_attribution_revision_person` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision_no` int(10) unsigned NOT NULL,
  `role` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `employee_name_snapshot` varchar(128) NOT NULL,
  `employee_type_snapshot` varchar(64) NOT NULL DEFAULT '',
  `business_date` date NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operation_person` (`tenant_id`,`operation_id`,`revision_no`,`role`,`employee_id`),
  KEY `idx_operation_version` (`tenant_id`,`operation_id`,`revision_no`,`role`),
  KEY `idx_employee_date` (`tenant_id`,`employee_id`,`business_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='退款导购销售经理每版人员明细';
