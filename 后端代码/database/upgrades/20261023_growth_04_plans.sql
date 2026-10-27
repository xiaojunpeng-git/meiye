-- MYDX-P1-04：地图模板发布与计划分配分离，名单补发、退出均保留记录。
CREATE TABLE IF NOT EXISTS `eb_growth_map` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(180) NOT NULL,
 `description` text NOT NULL, `sequential` tinyint unsigned NOT NULL DEFAULT 1,
 `service_product_id` int unsigned NOT NULL DEFAULT 0, `draft_json` mediumtext NOT NULL,
 `release_id` bigint unsigned NOT NULL DEFAULT 0,
 `owner_employee_id` int unsigned NOT NULL DEFAULT 0, `created_by` varchar(64) NOT NULL,
 `created_at` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_map_release` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `map_id` bigint unsigned NOT NULL,
 `snapshot_json` mediumtext NOT NULL, `published_by` varchar(64) NOT NULL, `published_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_plan` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(180) NOT NULL,
 `map_release_id` bigint unsigned NOT NULL, `sequential` tinyint unsigned NOT NULL DEFAULT 1,
 `responsible_employee_id` int unsigned NOT NULL DEFAULT 0,
 `starts_at` int unsigned NOT NULL DEFAULT 0, `due_at` int unsigned NOT NULL DEFAULT 0,
 `state` varchar(20) NOT NULL DEFAULT 'ACTIVE', `created_by` varchar(64) NOT NULL,
 `owner_employee_id` int unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_plan_employee` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `plan_id` bigint unsigned NOT NULL,
 `employee_id` int unsigned NOT NULL, `mentor_employee_id` int unsigned NOT NULL DEFAULT 0,
 `state` varchar(20) NOT NULL DEFAULT 'ACTIVE', `assigned_at` int unsigned NOT NULL,
 `withdrawn_at` int unsigned NOT NULL DEFAULT 0, `withdrawal_reason` text NOT NULL,
 UNIQUE KEY `uq_participant` (`plan_id`,`employee_id`), KEY `ix_employee` (`employee_id`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_onboarding_rule` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(180) NOT NULL,
 `enabled` tinyint unsigned NOT NULL DEFAULT 0, `organization_id` int unsigned NOT NULL DEFAULT 0,
 `store_id` int unsigned NOT NULL DEFAULT 0, `position_id` int unsigned NOT NULL DEFAULT 0,
 `map_release_id` bigint unsigned NOT NULL, `relative_days` int unsigned NOT NULL DEFAULT 30,
 `responsible_employee_id` int unsigned NOT NULL,
 `owner_employee_id` int unsigned NOT NULL DEFAULT 0, `created_by` varchar(64) NOT NULL,
 `created_at` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_onboarding_dispatch` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `rule_id` bigint unsigned NOT NULL, `employee_id` int unsigned NOT NULL,
 `source_event_key` varchar(128) NOT NULL, `plan_id` bigint unsigned NOT NULL DEFAULT 0,
 `state` varchar(20) NOT NULL, `failure_reason` varchar(255) NOT NULL DEFAULT '',
 `created_at` int unsigned NOT NULL, UNIQUE KEY `uq_dispatch` (`rule_id`,`employee_id`,`source_event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_reminder` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `source_key` varchar(128) NOT NULL,
 `employee_id` int unsigned NOT NULL, `task_id` bigint unsigned NOT NULL DEFAULT 0,
 `reminder_type` varchar(30) NOT NULL, `due_at` int unsigned NOT NULL,
 `state` varchar(20) NOT NULL DEFAULT 'PENDING', `sent_at` int unsigned NOT NULL DEFAULT 0,
 UNIQUE KEY `uq_reminder` (`source_key`,`employee_id`), KEY `ix_due` (`state`,`due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
