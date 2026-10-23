-- MYDX-P1-06：检查标准共用，作业、实操鉴定、带教和活动记录按事实粒度分别保存。
CREATE TABLE IF NOT EXISTS `eb_growth_standard` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(180) NOT NULL,
 `purpose` varchar(24) NOT NULL, `release_id` bigint unsigned NOT NULL DEFAULT 0,
 `draft_json` mediumtext NOT NULL, `owner_employee_id` int unsigned NOT NULL DEFAULT 0,
 `created_by` varchar(64) NOT NULL, `updated_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_standard_release` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `standard_id` bigint unsigned NOT NULL,
 `snapshot_json` mediumtext NOT NULL, `published_by` varchar(64) NOT NULL, `published_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_capability` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `position_id` int unsigned NOT NULL,
 `title` varchar(180) NOT NULL, `description` text NOT NULL,
 `standard_release_id` bigint unsigned NOT NULL DEFAULT 0, `evidence_required` tinyint unsigned NOT NULL DEFAULT 0,
 `created_by` varchar(64) NOT NULL, `owner_employee_id` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_activity` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `kind` varchar(24) NOT NULL,
 `title` varchar(180) NOT NULL, `description` text NOT NULL,
 `standard_release_id` bigint unsigned NOT NULL DEFAULT 0, `capability_id` bigint unsigned NOT NULL DEFAULT 0,
 `handler_employee_id` int unsigned NOT NULL DEFAULT 0, `evidence_required` tinyint unsigned NOT NULL DEFAULT 0,
 `requires_confirmation` tinyint unsigned NOT NULL DEFAULT 1,
 `required_submissions` int unsigned NOT NULL DEFAULT 1, `max_resubmissions` int unsigned NOT NULL DEFAULT 2,
 `due_at` int unsigned NOT NULL DEFAULT 0, `valid_days` int unsigned NOT NULL DEFAULT 0,
 `location` varchar(255) NOT NULL DEFAULT '', `event_at` int unsigned NOT NULL DEFAULT 0,
 `created_by` varchar(64) NOT NULL, `owner_employee_id` int unsigned NOT NULL DEFAULT 0,
 `created_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_submission` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `task_id` bigint unsigned NOT NULL,
 `employee_id` int unsigned NOT NULL, `attempt_no` int unsigned NOT NULL,
 `previous_submission_id` bigint unsigned NOT NULL DEFAULT 0, `snapshot_json` mediumtext NOT NULL,
 `state` varchar(24) NOT NULL DEFAULT 'PENDING', `submitted_at` int unsigned NOT NULL,
 `reviewer_employee_id` int unsigned NOT NULL DEFAULT 0, `review_json` mediumtext NULL,
 `reviewed_at` int unsigned NOT NULL DEFAULT 0, UNIQUE KEY `uq_attempt` (`task_id`,`attempt_no`),
 KEY `ix_review` (`reviewer_employee_id`,`state`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_evaluation` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `task_id` bigint unsigned NOT NULL,
 `employee_id` int unsigned NOT NULL, `capability_id` bigint unsigned NOT NULL DEFAULT 0,
 `standard_release_id` bigint unsigned NOT NULL, `previous_evaluation_id` bigint unsigned NOT NULL DEFAULT 0,
 `preparation_json` mediumtext NOT NULL, `result_json` mediumtext NOT NULL,
 `conclusion` varchar(24) NOT NULL, `score` int unsigned NULL, `evaluator_employee_id` int unsigned NOT NULL,
 `occurred_at` int unsigned NOT NULL, `valid_until` int unsigned NOT NULL DEFAULT 0,
 KEY `ix_capability` (`employee_id`,`capability_id`,`occurred_at`), KEY `ix_task` (`task_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_coaching` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `task_id` bigint unsigned NOT NULL,
 `employee_id` int unsigned NOT NULL, `mentor_employee_id` int unsigned NOT NULL,
 `snapshot_json` mediumtext NOT NULL, `occurred_at` int unsigned NOT NULL, `recorded_at` int unsigned NOT NULL,
 KEY `ix_task` (`task_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_attendance` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `task_id` bigint unsigned NOT NULL,
 `activity_id` bigint unsigned NOT NULL, `employee_id` int unsigned NOT NULL,
 `previous_attendance_id` bigint unsigned NOT NULL DEFAULT 0, `attended` tinyint unsigned NOT NULL, `evidence_json` mediumtext NOT NULL,
 `confirmer_employee_id` int unsigned NOT NULL, `occurred_at` int unsigned NOT NULL,
 KEY `ix_attendance_task` (`task_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_asset_reference` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `asset_id` bigint unsigned NOT NULL,
 `reference_type` varchar(32) NOT NULL, `reference_id` bigint unsigned NOT NULL,
 `task_id` bigint unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL,
 UNIQUE KEY `uq_reference` (`asset_id`,`reference_type`,`reference_id`), KEY `ix_task` (`task_id`,`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_help_question` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `employee_id` int unsigned NOT NULL,
 `handler_employee_id` int unsigned NOT NULL, `task_id` bigint unsigned NOT NULL DEFAULT 0,
 `body` text NOT NULL, `state` varchar(20) NOT NULL DEFAULT 'OPEN',
 `knowledge_content_id` bigint unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL,
 KEY `ix_handler` (`handler_employee_id`,`state`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_help_reply` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `question_id` bigint unsigned NOT NULL,
 `employee_id` int unsigned NOT NULL, `body` text NOT NULL,
 `transfer_to` int unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL,
 KEY `ix_question` (`question_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
