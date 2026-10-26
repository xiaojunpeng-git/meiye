-- MYDX-P1-08：员工积分独立于会员积分；调整追加流水，证书来源与历史不覆盖。
CREATE TABLE IF NOT EXISTS `eb_growth_point_rule` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(180) NOT NULL,
 `enabled` tinyint unsigned NOT NULL DEFAULT 1, `event_type` varchar(64) NOT NULL,
 `points` int unsigned NOT NULL, `require_passed` tinyint unsigned NOT NULL DEFAULT 0,
 `first_only` tinyint unsigned NOT NULL DEFAULT 1, `max_awards` int unsigned NOT NULL DEFAULT 1,
 `created_by` varchar(64) NOT NULL, `created_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_point_ledger` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `employee_id` int unsigned NOT NULL,
 `delta` int NOT NULL, `rule_id` bigint unsigned NOT NULL DEFAULT 0,
 `source_type` varchar(32) NOT NULL, `source_id` bigint unsigned NOT NULL DEFAULT 0,
 `source_key` varchar(160) NOT NULL, `source_group` varchar(128) NOT NULL DEFAULT '', `reversal_of` bigint unsigned NOT NULL DEFAULT 0,
 `reason` text NOT NULL, `evidence_json` mediumtext NOT NULL,
 `actor_key` varchar(64) NOT NULL, `occurred_at` int unsigned NOT NULL,
 UNIQUE KEY `uq_source` (`employee_id`,`source_key`),
 KEY `ix_employee` (`employee_id`,`occurred_at`,`id`), KEY `ix_reversal` (`reversal_of`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_level` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(100) NOT NULL,
 `threshold` int unsigned NOT NULL, `enabled` tinyint unsigned NOT NULL DEFAULT 1,
 UNIQUE KEY `uq_threshold` (`threshold`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_certificate_rule` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(180) NOT NULL,
 `enabled` tinyint unsigned NOT NULL DEFAULT 1, `event_type` varchar(64) NOT NULL,
 `require_passed` tinyint unsigned NOT NULL DEFAULT 1, `valid_days` int unsigned NOT NULL DEFAULT 0,
 `responsible_employee_id` int unsigned NOT NULL, `created_by` varchar(64) NOT NULL, `created_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_certificate` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `employee_id` int unsigned NOT NULL,
 `title` varchar(180) NOT NULL, `certificate_number` varchar(100) NOT NULL,
 `origin` varchar(16) NOT NULL, `rule_id` bigint unsigned NOT NULL DEFAULT 0,
 `source_type` varchar(32) NOT NULL, `source_id` bigint unsigned NOT NULL DEFAULT 0,
 `source_key` varchar(160) NOT NULL, `state` varchar(20) NOT NULL DEFAULT 'VALID',
 `evidence_json` mediumtext NOT NULL, `issued_at` int unsigned NOT NULL,
 `expires_at` int unsigned NOT NULL DEFAULT 0, `created_by` varchar(64) NOT NULL,
 UNIQUE KEY `uq_certificate_source` (`employee_id`,`source_key`),
 KEY `ix_expiry` (`state`,`expires_at`), KEY `ix_employee` (`employee_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_certificate_action` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `certificate_id` bigint unsigned NOT NULL,
 `action` varchar(30) NOT NULL, `reason` text NOT NULL, `evidence_json` mediumtext NOT NULL,
 `old_expires_at` int unsigned NOT NULL DEFAULT 0, `new_expires_at` int unsigned NOT NULL DEFAULT 0,
 `source_event_id` bigint unsigned NOT NULL DEFAULT 0, `actor_key` varchar(64) NOT NULL,
 `created_at` int unsigned NOT NULL, KEY `ix_certificate` (`certificate_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_delivery_attempt` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `event_id` bigint unsigned NOT NULL,
 `state` varchar(20) NOT NULL, `error_message` varchar(500) NOT NULL DEFAULT '',
 `created_at` int unsigned NOT NULL, KEY `ix_event` (`event_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_appraisal` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `employee_id` int unsigned NOT NULL,
 `employee_name` varchar(100) NOT NULL, `title` varchar(180) NOT NULL,
 `period_start` date NOT NULL, `period_end` date NOT NULL,
 `standard_release_id` bigint unsigned NOT NULL, `handler_employee_id` int unsigned NOT NULL,
 `state` varchar(24) NOT NULL DEFAULT 'ASSIGNED', `created_by` varchar(64) NOT NULL,
 `created_at` int unsigned NOT NULL, KEY `ix_employee` (`employee_id`,`period_end`),
 KEY `ix_handler` (`handler_employee_id`,`state`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_appraisal_result` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `appraisal_id` bigint unsigned NOT NULL,
 `previous_result_id` bigint unsigned NOT NULL DEFAULT 0, `snapshot_json` mediumtext NOT NULL,
 `score` int unsigned NULL, `passed` tinyint unsigned NOT NULL,
 `reason` text NOT NULL, `handler_employee_id` int unsigned NOT NULL, `confirmed_at` int unsigned NOT NULL,
 KEY `ix_appraisal` (`appraisal_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_objection` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `appraisal_id` bigint unsigned NOT NULL,
 `result_id` bigint unsigned NOT NULL, `employee_id` int unsigned NOT NULL,
 `handler_employee_id` int unsigned NOT NULL, `body` text NOT NULL, `evidence_json` mediumtext NOT NULL,
 `state` varchar(20) NOT NULL DEFAULT 'OPEN', `resolution` text NULL,
 `created_at` int unsigned NOT NULL, `resolved_at` int unsigned NOT NULL DEFAULT 0,
 KEY `ix_handler` (`handler_employee_id`,`state`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
