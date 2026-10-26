-- MYDX-P1-05：题库、试卷发布、考试和答卷分粒度保存；每次尝试只有一份正式答案。
CREATE TABLE IF NOT EXISTS `eb_growth_question` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `type` varchar(16) NOT NULL,
 `stem` text NOT NULL, `options_json` text NOT NULL, `answer_json` text NOT NULL,
 `basis` text NOT NULL, `explanation` text NOT NULL, `category` varchar(100) NOT NULL DEFAULT '',
 `score` int unsigned NOT NULL DEFAULT 1, `state` varchar(16) NOT NULL DEFAULT 'ACTIVE',
 `owner_employee_id` int unsigned NOT NULL DEFAULT 0, `created_by` varchar(64) NOT NULL,
 `created_at` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL,
 KEY `ix_pool` (`state`,`type`,`category`,`id`), KEY `ix_owner` (`owner_employee_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_paper` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(180) NOT NULL,
 `draft_json` mediumtext NOT NULL, `created_by` varchar(64) NOT NULL,
 `owner_employee_id` int unsigned NOT NULL DEFAULT 0,
 `release_id` bigint unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_paper_release` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `paper_id` bigint unsigned NOT NULL,
 `title` varchar(180) NOT NULL, `snapshot_json` mediumtext NOT NULL,
 `published_at` int unsigned NOT NULL, `published_by` varchar(64) NOT NULL,
 KEY `ix_paper` (`paper_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_exam` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `title` varchar(180) NOT NULL,
 `kind` varchar(16) NOT NULL DEFAULT 'exam', `paper_release_id` bigint unsigned NOT NULL,
 `state` varchar(20) NOT NULL DEFAULT 'OPEN', `starts_at` int unsigned NOT NULL DEFAULT 0,
 `ends_at` int unsigned NOT NULL DEFAULT 0, `duration_seconds` int unsigned NOT NULL DEFAULT 0,
 `max_attempts` int unsigned NOT NULL DEFAULT 1, `pass_score` int unsigned NOT NULL DEFAULT 60,
 `grader_employee_id` int unsigned NOT NULL DEFAULT 0, `auto_publish` tinyint unsigned NOT NULL DEFAULT 1,
 `show_analysis` tinyint unsigned NOT NULL DEFAULT 0, `required_pass` tinyint unsigned NOT NULL DEFAULT 1,
 `rules_json` mediumtext NOT NULL, `created_by` varchar(64) NOT NULL,
 `owner_employee_id` int unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL,
 KEY `ix_open` (`state`,`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_attempt` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `task_id` bigint unsigned NOT NULL,
 `exam_id` bigint unsigned NOT NULL, `employee_id` int unsigned NOT NULL,
 `attempt_no` int unsigned NOT NULL, `previous_attempt_id` bigint unsigned NOT NULL DEFAULT 0,
 `state` varchar(20) NOT NULL DEFAULT 'ANSWERING',
 `questions_json` mediumtext NOT NULL, `draft_answers_json` mediumtext NOT NULL,
 `final_answers_json` mediumtext NULL, `grades_json` mediumtext NULL,
 `answer_session_key` varchar(100) NOT NULL, `started_at` int unsigned NOT NULL,
 `deadline_at` int unsigned NOT NULL DEFAULT 0, `saved_at` int unsigned NOT NULL DEFAULT 0,
 `submitted_at` int unsigned NOT NULL DEFAULT 0, `published_at` int unsigned NOT NULL DEFAULT 0,
 `score` int unsigned NOT NULL DEFAULT 0, `max_score` int unsigned NOT NULL,
 `passed` tinyint unsigned NOT NULL DEFAULT 0,
 UNIQUE KEY `uq_attempt` (`task_id`,`attempt_no`), KEY `ix_deadline` (`state`,`deadline_at`),
 KEY `ix_employee` (`employee_id`,`exam_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_grade_adjustment` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `attempt_id` bigint unsigned NOT NULL,
 `previous_adjustment_id` bigint unsigned NOT NULL DEFAULT 0,
 `score` int unsigned NOT NULL, `passed` tinyint unsigned NOT NULL,
 `grades_json` mediumtext NOT NULL, `reason` text NOT NULL,
 `actor_key` varchar(64) NOT NULL, `created_at` int unsigned NOT NULL,
 KEY `ix_attempt` (`attempt_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_question_feedback` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `question_id` bigint unsigned NOT NULL,
 `employee_id` int unsigned NOT NULL, `description` text NOT NULL,
 `state` varchar(16) NOT NULL DEFAULT 'OPEN', `resolution` text NOT NULL,
 `handler_employee_id` int unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL,
 `resolved_at` int unsigned NOT NULL DEFAULT 0, KEY `ix_state` (`state`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
