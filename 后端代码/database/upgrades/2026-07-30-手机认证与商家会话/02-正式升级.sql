-- upgrade_key: 20260730-003-mobile-auth-v1-session-security
-- MySQL 5.6.51 compatible. New tables only; no historical business-table writes.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_employee_mobile_auth_state` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `employee_id` bigint unsigned NOT NULL,
 `phone_binding_version` bigint unsigned NOT NULL DEFAULT 1, `auth_version` bigint unsigned NOT NULL DEFAULT 1,
 `merchant_session_epoch` bigint unsigned NOT NULL DEFAULT 1, `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_employee_phone_binding` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `phone_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `employee_id` bigint unsigned DEFAULT NULL, `state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'RESERVED',
 `bound_at` int unsigned NOT NULL DEFAULT 0, `released_at` int unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_phone_digest` (`phone_digest`), UNIQUE KEY `uk_employee_id` (`employee_id`), KEY `idx_state_updated` (`state`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_employee_phone_binding_audit` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `phone_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `before_employee_id` bigint unsigned DEFAULT NULL, `after_employee_id` bigint unsigned DEFAULT NULL, `employee_phone_binding_version` bigint unsigned NOT NULL DEFAULT 1,
 `action` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `operator_id` bigint unsigned NOT NULL DEFAULT 0,
 `request_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '', `occurred_at` int unsigned NOT NULL DEFAULT 0, `recorded_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), KEY `idx_employee_time` (`after_employee_id`,`recorded_at`), KEY `idx_phone_time` (`phone_digest`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_mobile_captcha_challenge` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `challenge_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `proof_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL, `phone_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `purpose` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `client_session_id_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `installation_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING',
 `expires_at` int unsigned NOT NULL DEFAULT 0, `consumed_at` int unsigned NOT NULL DEFAULT 0, `invalidated_at` int unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_challenge_id` (`challenge_id`), UNIQUE KEY `uk_proof_hash` (`proof_hash`), KEY `idx_phone_purpose_time` (`phone_digest`,`purpose`,`created_at`), KEY `idx_state_expiry` (`state`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_mobile_sms_challenge` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `challenge_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `phone_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `purpose` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `captcha_challenge_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `client_session_id_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `installation_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `ip_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `code_hmac` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `code_salt` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `key_version` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `idempotency_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `challenge_state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CREATED', `delivery_state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDING',
 `attempt_count` tinyint unsigned NOT NULL DEFAULT 0, `locked_until` int unsigned NOT NULL DEFAULT 0, `expires_at` int unsigned NOT NULL DEFAULT 0, `consumed_at` int unsigned NOT NULL DEFAULT 0,
 `provider_request_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL, `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_challenge_id` (`challenge_id`), UNIQUE KEY `uk_purpose_phone_idem` (`purpose`,`phone_digest`,`idempotency_key_hash`), KEY `idx_phone_purpose_time` (`phone_digest`,`purpose`,`created_at`), KEY `idx_install_time` (`installation_digest`,`created_at`), KEY `idx_ip_time` (`ip_digest`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_mobile_app_session` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `app_session_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `uid` bigint unsigned NOT NULL,
 `verification_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL, `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `client_session_id_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `installation_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE', `expires_at` int unsigned NOT NULL DEFAULT 0, `signed_out_at` int unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_app_session_id` (`app_session_id`), UNIQUE KEY `uk_token_hash` (`token_hash`), KEY `idx_uid_state_expiry` (`uid`,`state`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_mobile_phone_verification` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `verification_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `app_session_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `verified_phone_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `identity_version` bigint unsigned NOT NULL, `employee_id_snapshot` bigint unsigned DEFAULT NULL, `employee_phone_binding_version_snapshot` bigint unsigned DEFAULT NULL,
 `method` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE',
 `verified_at` int unsigned NOT NULL DEFAULT 0, `expires_at` int unsigned NOT NULL DEFAULT 0, `invalidated_at` int unsigned NOT NULL DEFAULT 0, `merchant_consumed_at` int unsigned NOT NULL DEFAULT 0, `merchant_consume_idempotency_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL, `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_verification_id` (`verification_id`), KEY `idx_app_current` (`app_session_id`,`state`,`verified_at`), KEY `idx_phone_state_expiry` (`verified_phone_digest`,`state`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_mobile_merchant_lease` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `employee_id` bigint unsigned NOT NULL, `current_session_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `current_epoch` bigint unsigned NOT NULL DEFAULT 1, `state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'EMPTY', `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_employee_id` (`employee_id`), UNIQUE KEY `uk_current_session` (`current_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_mobile_merchant_session` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `session_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `employee_id` bigint unsigned NOT NULL, `origin_app_session_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `client_session_id_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `installation_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `auth_version` bigint unsigned NOT NULL, `session_epoch` bigint unsigned NOT NULL, `employee_phone_binding_version` bigint unsigned NOT NULL, `elevation_use_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `verification_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE', `expires_at` int unsigned NOT NULL DEFAULT 0, `revoked_at` int unsigned NOT NULL DEFAULT 0, `session_end_cause` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'NONE', `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_session_id` (`session_id`), UNIQUE KEY `uk_token_hash` (`token_hash`), KEY `idx_employee_state_time` (`employee_id`,`state`,`created_at`), KEY `idx_client_session` (`client_session_id_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_mobile_merchant_business_context` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `context_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `employee_id` bigint unsigned NOT NULL, `context_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `staff_id` bigint unsigned NOT NULL DEFAULT 0, `store_id` bigint unsigned NOT NULL DEFAULT 0, `organization_id` bigint unsigned NOT NULL DEFAULT 0, `label` varchar(96) NOT NULL, `state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE', `created_at` int unsigned NOT NULL DEFAULT 0, `closed_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_context_id` (`context_id`), UNIQUE KEY `uk_employee_context` (`employee_id`,`context_type`,`staff_id`,`store_id`,`organization_id`), KEY `idx_employee_state` (`employee_id`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_mobile_merchant_session_projection` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `session_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `business_context_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `active_context_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `state_context_id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `state_revision` bigint unsigned NOT NULL DEFAULT 1, `state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE', `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_session_id` (`session_id`), UNIQUE KEY `uk_active_context_id` (`active_context_id`), UNIQUE KEY `uk_state_context_id` (`state_context_id`), KEY `idx_business_state` (`business_context_id`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_mobile_merchant_idempotency` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `employee_id` bigint unsigned NOT NULL, `actor_employee_id` bigint unsigned NOT NULL DEFAULT 0, `command_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `idempotency_key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `state` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'IN_PROGRESS', `response_key_version` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '', `response_ciphertext` mediumtext NOT NULL, `expires_at` int unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_employee_command_key` (`employee_id`,`command_code`,`idempotency_key_hash`), KEY `idx_state_expiry` (`state`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_mobile_merchant_security_audit` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `employee_id` bigint unsigned NOT NULL, `actor_employee_id` bigint unsigned NOT NULL DEFAULT 0, `event_code` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `session_id` char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL, `lease_epoch_before` bigint unsigned NOT NULL DEFAULT 0, `lease_epoch_after` bigint unsigned NOT NULL DEFAULT 0, `auth_version_before` bigint unsigned NOT NULL DEFAULT 0, `auth_version_after` bigint unsigned NOT NULL DEFAULT 0, `installation_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL, `request_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '', `reason_code` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `payload` text NOT NULL, `occurred_at` int unsigned NOT NULL DEFAULT 0, `recorded_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), KEY `idx_employee_time` (`employee_id`,`recorded_at`), KEY `idx_request` (`request_id`), KEY `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Establish current employee phone binding snapshots without changing eb_employee.
INSERT INTO `eb_employee_mobile_auth_state` (`employee_id`,`phone_binding_version`,`auth_version`,`merchant_session_epoch`,`created_at`,`updated_at`)
SELECT e.`id`,1,GREATEST(1,IFNULL(e.`auth_version`,1)),1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `eb_employee` e WHERE e.`is_del`=0
ON DUPLICATE KEY UPDATE `auth_version`=GREATEST(`eb_employee_mobile_auth_state`.`auth_version`,VALUES(`auth_version`)),`updated_at`=VALUES(`updated_at`);
INSERT INTO `eb_employee_phone_binding` (`phone_digest`,`employee_id`,`state`,`bound_at`,`released_at`,`created_at`,`updated_at`)
SELECT SHA2(e.`phone`,256),e.`id`,'BOUND',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `eb_employee` e WHERE e.`is_del`=0 AND e.`phone` REGEXP '^1[3-9][0-9]{9}$'
ON DUPLICATE KEY UPDATE `employee_id`=VALUES(`employee_id`),`state`='BOUND',`bound_at`=VALUES(`bound_at`),`released_at`=0,`updated_at`=VALUES(`updated_at`);

-- Do not register the upgrade here: DDL is non-transactional and the registry
-- requires execution evidence. After structural/data verification, the release
-- workflow writes the complete record with this file's real SHA-256.
-- INSERT INTO `eb_database_upgrade_log`
-- (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
-- VALUES
-- ('20260730-003-mobile-auth-v1-session-security','手机认证与商家会话',
--  '后端代码/database/upgrades/2026-07-30-手机认证与商家会话/02-正式升级.sql',
--  '<02-正式升级.sql真实SHA-256>','<commit>',NOW(),'<执行人>','<备份、前检、结构与数据验证证据>');
