-- R51：仅新增人工登记编辑审计，不迁移/覆盖已有salary_store，不产生资金或经营事实。
-- 各指定实例须在固定候选验收、独立备份及迁移授权后执行；不重复导入已有刷单，不改未授权实例。
CREATE TABLE IF NOT EXISTS `eb_basic_report_registration_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `registration_id` bigint unsigned NOT NULL,
  `store_id` int unsigned NOT NULL,
  `record_version` int unsigned NOT NULL,
  `actor_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `before_json` mediumtext NOT NULL,
  `after_json` mediumtext NOT NULL,
  `recorded_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_actor_request` (`actor_key`,`idempotency_key`),
  UNIQUE KEY `uk_registration_version` (`registration_id`,`record_version`),
  KEY `idx_store_recorded` (`store_id`,`recorded_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
