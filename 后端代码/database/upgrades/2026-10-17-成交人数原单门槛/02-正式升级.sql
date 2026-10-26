-- R41: 成交人数门槛按客户独立实例保存历史版本；原单取结账时生效版本。
-- 只新增配置历史，不重写销售、收款、退款或既有报表事实。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_deal_people_threshold_policy` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `version` bigint(20) unsigned NOT NULL,
  `a_threshold_cents` bigint(20) unsigned NOT NULL,
  `other_threshold_cents` bigint(20) unsigned NOT NULL,
  `effective_at` bigint(20) unsigned NOT NULL,
  `changed_by` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_version` (`tenant_id`,`version`),
  KEY `idx_tenant_effective` (`tenant_id`,`effective_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='R41 sale-order deal-person threshold versions';

-- 默认版本也是可锁定的配置行，避免两位管理员首次保存时都读到空表。
INSERT IGNORE INTO `eb_cashier_v3_deal_people_threshold_policy`
  (`tenant_id`, `version`, `a_threshold_cents`, `other_threshold_cents`, `effective_at`, `changed_by`, `created_at`)
VALUES ('0', 0, 100000, 50000, 0, 0, 0);
