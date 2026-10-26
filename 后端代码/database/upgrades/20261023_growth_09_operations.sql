-- MYDX-P1-09：只消费规则启用后的首次正式任职；游标不是表单版本。
CREATE TABLE IF NOT EXISTS `eb_growth_onboarding_cursor` (
 `rule_id` bigint unsigned NOT NULL PRIMARY KEY, `last_job_id` bigint unsigned NOT NULL DEFAULT 0,
 `enabled_since` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_export` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `actor_key` varchar(64) NOT NULL,
 `query_json` mediumtext NOT NULL, `scope_json` mediumtext NOT NULL, `state` varchar(20) NOT NULL,
 `row_count` int unsigned NOT NULL DEFAULT 0, `file_key` varchar(255) NOT NULL DEFAULT '',
 `error_message` varchar(500) NOT NULL DEFAULT '', `requested_at` int unsigned NOT NULL,
 `completed_at` int unsigned NOT NULL DEFAULT 0, KEY `ix_actor` (`actor_key`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_review` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(180) NOT NULL,
 `object_type` varchar(20) NOT NULL, `object_id` int unsigned NOT NULL,
 `plan_id` bigint unsigned NOT NULL DEFAULT 0, `issue_id` bigint unsigned NOT NULL DEFAULT 0,
 `baseline_start` date NOT NULL, `baseline_end` date NOT NULL, `followup_start` date NOT NULL, `followup_end` date NOT NULL,
 `metrics_json` mediumtext NOT NULL, `snapshot_json` mediumtext NOT NULL,
 `background` text NOT NULL, `judgment` text NOT NULL, `next_steps` text NOT NULL,
 `followup_task_id` bigint unsigned NOT NULL DEFAULT 0, `owner_employee_id` int unsigned NOT NULL,
 `created_by` varchar(64) NOT NULL, `created_at` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL,
 KEY `ix_owner` (`owner_employee_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
