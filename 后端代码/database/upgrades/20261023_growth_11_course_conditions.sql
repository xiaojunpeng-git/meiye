-- MYDX-P1-11：随堂每次正式作答独立留痕；课程后续任务只记录关联，不复制答卷或鉴定结果。
CREATE TABLE IF NOT EXISTS `eb_growth_inline_attempt` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `task_id` bigint unsigned NOT NULL,
 `chapter_key` varchar(80) NOT NULL, `quiz_key` varchar(80) NOT NULL,
 `employee_id` int unsigned NOT NULL, `question_id` bigint unsigned NOT NULL,
 `snapshot_json` mediumtext NOT NULL, `passed` tinyint unsigned NOT NULL, `score` int unsigned NOT NULL,
 `submitted_at` int unsigned NOT NULL, KEY `ix_quiz` (`task_id`,`chapter_key`,`quiz_key`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_task_dependency` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `parent_task_id` bigint unsigned NOT NULL, `child_task_id` bigint unsigned NOT NULL,
 `dependency_type` varchar(20) NOT NULL, `require_passed` tinyint unsigned NOT NULL DEFAULT 1,
 UNIQUE KEY `uq_child` (`parent_task_id`,`child_task_id`), KEY `ix_child` (`child_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_result_reference` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `task_id` bigint unsigned NOT NULL,
 `source_task_id` bigint unsigned NOT NULL, `employee_id` int unsigned NOT NULL,
 `reason` text NOT NULL, `actor_key` varchar(64) NOT NULL, `created_at` int unsigned NOT NULL,
 UNIQUE KEY `uq_task_reference` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
