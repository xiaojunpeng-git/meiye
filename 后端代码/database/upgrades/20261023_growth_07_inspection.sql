-- MYDX-P1-07：巡检提交、整改提交与复查是三个不同业务事实，只有复查通过关闭问题。
CREATE TABLE IF NOT EXISTS `eb_growth_inspection` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(180) NOT NULL,
 `store_id` int unsigned NOT NULL, `store_name` varchar(100) NOT NULL,
 `executor_employee_id` int unsigned NOT NULL, `reviewer_employee_id` int unsigned NOT NULL DEFAULT 0,
 `standard_release_id` bigint unsigned NOT NULL, `due_at` int unsigned NOT NULL DEFAULT 0,
 `state` varchar(24) NOT NULL DEFAULT 'ASSIGNED', `result_json` mediumtext NULL,
 `occurred_at` int unsigned NOT NULL DEFAULT 0, `submitted_at` int unsigned NOT NULL DEFAULT 0,
 `reviewed_at` int unsigned NOT NULL DEFAULT 0, `review_note` text NULL, `created_by` varchar(64) NOT NULL,
 `owner_employee_id` int unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL,
 KEY `ix_store` (`store_id`,`state`,`id`), KEY `ix_executor` (`executor_employee_id`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_issue` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `inspection_id` bigint unsigned NOT NULL,
 `task_id` bigint unsigned NOT NULL DEFAULT 0, `item_key` varchar(64) NOT NULL, `item_title` varchar(180) NOT NULL, `store_id` int unsigned NOT NULL,
 `store_name` varchar(100) NOT NULL, `description` text NOT NULL, `original_evidence_json` mediumtext NOT NULL,
 `responsible_employee_id` int unsigned NOT NULL, `reviewer_employee_id` int unsigned NOT NULL,
 `due_at` int unsigned NOT NULL, `state` varchar(24) NOT NULL DEFAULT 'OPEN',
 `created_at` int unsigned NOT NULL, `closed_at` int unsigned NOT NULL DEFAULT 0,
 `closed_by` int unsigned NOT NULL DEFAULT 0,
 UNIQUE KEY `uq_problem` (`inspection_id`,`item_key`),
 KEY `ix_responsible` (`responsible_employee_id`,`state`,`due_at`), KEY `ix_repeat` (`store_id`,`item_key`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_rectification` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `issue_id` bigint unsigned NOT NULL,
 `employee_id` int unsigned NOT NULL, `previous_rectification_id` bigint unsigned NOT NULL DEFAULT 0,
 `snapshot_json` mediumtext NOT NULL, `submitted_at` int unsigned NOT NULL,
 `state` varchar(20) NOT NULL DEFAULT 'PENDING', KEY `ix_issue` (`issue_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_issue_review` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `issue_id` bigint unsigned NOT NULL,
 `rectification_id` bigint unsigned NOT NULL, `reviewer_employee_id` int unsigned NOT NULL,
 `passed` tinyint unsigned NOT NULL, `note` text NOT NULL, `evidence_json` mediumtext NOT NULL,
 `reviewed_at` int unsigned NOT NULL, UNIQUE KEY `uq_review` (`rectification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_issue_action` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `issue_id` bigint unsigned NOT NULL,
 `action` varchar(24) NOT NULL, `reason` text NOT NULL,
 `before_json` mediumtext NOT NULL, `after_json` mediumtext NOT NULL,
 `actor_key` varchar(64) NOT NULL, `created_at` int unsigned NOT NULL,
 KEY `ix_issue` (`issue_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
