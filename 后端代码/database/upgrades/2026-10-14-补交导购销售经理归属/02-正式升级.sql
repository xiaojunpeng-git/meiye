-- 归属版本为完整快照。空人员集仍须有版本行，防止旧版与清空混淆。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_supplement_attribution_revision` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `revision_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `repayment_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `repayment_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `member_id` bigint(20) unsigned NOT NULL,
  `revision_no` bigint(20) unsigned NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `old_snapshot_json` mediumtext NOT NULL,
  `new_snapshot_json` mediumtext NOT NULL,
  `reason` varchar(255) NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_revision_id` (`revision_id`),
  UNIQUE KEY `uk_repayment_version` (`tenant_id`,`repayment_id`,`revision_no`),
  UNIQUE KEY `uk_tenant_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_repayment_latest` (`tenant_id`,`repayment_id`,`revision_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='补交人员归属追加式版本';

-- 金额只在销售经理行记录该笔补交的归属基数；导购只记录人员和轮次。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_supplement_attribution_person` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `repayment_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `revision_no` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `role` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `employee_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `employee_type_snapshot` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `guide_round_no` tinyint(3) unsigned NULL,
  `amount_cents` bigint(20) NOT NULL DEFAULT 0,
  `business_date` date NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_repayment_role_employee` (`tenant_id`,`repayment_id`,`revision_no`,`role`,`employee_id`),
  KEY `idx_employee_date` (`tenant_id`,`employee_id`,`role`,`business_date`),
  KEY `idx_repayment_version` (`tenant_id`,`repayment_id`,`revision_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='补交人员归属版本明细';
