-- Current store master remains authoritative; this table is audit/idempotency only.
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_store_profile_audit` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `store_id` INT UNSIGNED NOT NULL,
 `request_token` VARCHAR(64) NOT NULL,
 `input_hash` CHAR(64) NOT NULL,
 `operator_id` INT UNSIGNED NOT NULL,
 `operator_name` VARCHAR(100) NOT NULL DEFAULT '',
 `before_json` MEDIUMTEXT NOT NULL,
 `after_json` MEDIUMTEXT NOT NULL,
 `created_at` INT UNSIGNED NOT NULL,
 PRIMARY KEY (`id`),
 UNIQUE KEY `uq_store_profile_request` (`store_id`,`request_token`),
 KEY `idx_store_profile_history` (`store_id`,`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店基础资料修改审计，不记录经营事实';
