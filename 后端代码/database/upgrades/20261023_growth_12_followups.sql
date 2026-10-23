-- MYDX-P1-12：补训与整改分开，等级变化保留当时门槛及积分来源。
CREATE TABLE IF NOT EXISTS `eb_growth_issue_training` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `issue_id` bigint unsigned NOT NULL,
 `task_id` bigint unsigned NOT NULL, `reason` text NOT NULL, `assigned_by` varchar(64) NOT NULL,
 `assigned_at` int unsigned NOT NULL, UNIQUE KEY `uq_task` (`issue_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_level_state` (
 `employee_id` int unsigned NOT NULL PRIMARY KEY, `level_id` bigint unsigned NOT NULL DEFAULT 0,
 `level_title` varchar(100) NOT NULL DEFAULT '', `point_balance` int NOT NULL,
 `source_ledger_id` bigint unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_level_event` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `employee_id` int unsigned NOT NULL,
 `previous_level_id` bigint unsigned NOT NULL DEFAULT 0, `previous_level_title` varchar(100) NOT NULL DEFAULT '',
 `level_id` bigint unsigned NOT NULL DEFAULT 0, `level_title` varchar(100) NOT NULL DEFAULT '',
 `point_balance` int NOT NULL, `source_ledger_id` bigint unsigned NOT NULL DEFAULT 0,
 `reason` varchar(80) NOT NULL, `rules_json` mediumtext NOT NULL, `occurred_at` int unsigned NOT NULL,
 KEY `ix_employee` (`employee_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
