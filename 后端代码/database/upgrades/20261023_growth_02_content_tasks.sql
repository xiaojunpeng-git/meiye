-- MYDX-P1-02：课程和发布、资源与任务各自保留权威粒度；历史发布不被草稿覆盖。
CREATE TABLE IF NOT EXISTS `eb_growth_content` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `kind` varchar(24) NOT NULL DEFAULT 'course', `title` varchar(180) NOT NULL,
 `category` varchar(100) NOT NULL DEFAULT '', `description` text NOT NULL,
 `cover_asset_id` bigint unsigned NOT NULL DEFAULT 0,
 `owner_employee_id` int unsigned NOT NULL DEFAULT 0,
 `organization_id` int unsigned NOT NULL DEFAULT 0, `store_id` int unsigned NOT NULL DEFAULT 0,
 `created_by` varchar(64) NOT NULL, `state` varchar(20) NOT NULL DEFAULT 'DRAFT',
 `draft_json` mediumtext NOT NULL, `current_publication_id` bigint unsigned NOT NULL DEFAULT 0,
 `created_at` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL,
 KEY `ix_scope` (`organization_id`,`store_id`,`state`,`id`), KEY `ix_owner` (`created_by`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_publication` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `content_id` bigint unsigned NOT NULL, `title` varchar(180) NOT NULL,
 `snapshot_json` mediumtext NOT NULL, `state` varchar(24) NOT NULL,
 `confirmer_employee_id` int unsigned NOT NULL DEFAULT 0,
 `published_by` varchar(64) NOT NULL, `created_at` int unsigned NOT NULL,
 `confirmed_at` int unsigned NOT NULL DEFAULT 0, `settled_at` int unsigned NOT NULL DEFAULT 0,
 KEY `ix_content` (`content_id`,`id`), KEY `ix_confirm` (`confirmer_employee_id`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_asset` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `owner_key` varchar(64) NOT NULL, `original_name` varchar(255) NOT NULL,
 `format` varchar(12) NOT NULL, `size_bytes` bigint unsigned NOT NULL,
 `storage_key` varchar(255) NOT NULL, `preview_key` varchar(255) NOT NULL DEFAULT '',
 `state` varchar(20) NOT NULL, `error_message` varchar(255) NOT NULL DEFAULT '',
 `page_count` int unsigned NOT NULL DEFAULT 0, `duration_seconds` int unsigned NOT NULL DEFAULT 0,
 `metadata_json` text NOT NULL, `created_at` int unsigned NOT NULL,
 KEY `ix_owner` (`owner_key`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_task` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `assignment_key` varchar(128) NOT NULL, `employee_id` int unsigned NOT NULL,
 `employee_name` varchar(100) NOT NULL, `organization_id` int unsigned NOT NULL DEFAULT 0,
 `store_id` int unsigned NOT NULL DEFAULT 0, `kind` varchar(24) NOT NULL,
 `title` varchar(180) NOT NULL, `publication_id` bigint unsigned NOT NULL DEFAULT 0,
 `source_type` varchar(30) NOT NULL DEFAULT 'single', `source_id` bigint unsigned NOT NULL DEFAULT 0,
 `plan_id` bigint unsigned NOT NULL DEFAULT 0, `stage_no` int unsigned NOT NULL DEFAULT 0,
 `required` tinyint unsigned NOT NULL DEFAULT 1, `handler_employee_id` int unsigned NOT NULL DEFAULT 0,
 `starts_at` int unsigned NOT NULL DEFAULT 0, `due_at` int unsigned NOT NULL DEFAULT 0,
 `allow_late` tinyint unsigned NOT NULL DEFAULT 1,
 `state` varchar(24) NOT NULL DEFAULT 'NOT_STARTED', `conclusion` varchar(24) NOT NULL DEFAULT 'UNTESTED',
 `rules_json` mediumtext NOT NULL, `result_type` varchar(30) NOT NULL DEFAULT '',
 `result_id` bigint unsigned NOT NULL DEFAULT 0, `assigned_by` varchar(64) NOT NULL,
 `assigned_at` int unsigned NOT NULL, `started_at` int unsigned NOT NULL DEFAULT 0,
 `completed_at` int unsigned NOT NULL DEFAULT 0,
 UNIQUE KEY `uq_assignment` (`assignment_key`,`employee_id`),
 KEY `ix_employee` (`employee_id`,`state`,`due_at`,`id`), KEY `ix_scope` (`store_id`,`organization_id`,`assigned_at`),
 KEY `ix_plan` (`plan_id`,`employee_id`,`stage_no`), KEY `ix_handler` (`handler_employee_id`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_learning` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `task_id` bigint unsigned NOT NULL, `employee_id` int unsigned NOT NULL,
 `chapter_key` varchar(80) NOT NULL, `position` int unsigned NOT NULL DEFAULT 0,
 `intervals_json` mediumtext NOT NULL, `confirmed` tinyint unsigned NOT NULL DEFAULT 0,
 `session_key` varchar(100) NOT NULL DEFAULT '', `last_received_at` int unsigned NOT NULL DEFAULT 0,
 `updated_at` int unsigned NOT NULL, UNIQUE KEY `uq_chapter` (`task_id`,`chapter_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_message` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `employee_id` int unsigned NOT NULL,
 `source_key` varchar(128) NOT NULL, `title` varchar(180) NOT NULL, `summary` text NOT NULL,
 `task_id` bigint unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL,
 `read_at` int unsigned NOT NULL DEFAULT 0, UNIQUE KEY `uq_message` (`employee_id`,`source_key`),
 KEY `ix_inbox` (`employee_id`,`read_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_task_action` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `task_id` bigint unsigned NOT NULL, `action` varchar(30) NOT NULL,
 `reason` text NOT NULL, `before_json` mediumtext NOT NULL, `after_json` mediumtext NOT NULL,
 `actor_key` varchar(64) NOT NULL, `created_at` int unsigned NOT NULL,
 KEY `ix_task` (`task_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_favorite` (
 `employee_id` int unsigned NOT NULL, `content_id` bigint unsigned NOT NULL,
 `created_at` int unsigned NOT NULL, PRIMARY KEY (`employee_id`,`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
