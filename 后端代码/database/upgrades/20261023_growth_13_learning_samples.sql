-- MYDX-P1-13：采集序号只用于学习事件去重和回溯，不作为表单保存版本。
CREATE TABLE IF NOT EXISTS `eb_growth_learning_sample` (
 `event_id` bigint unsigned NOT NULL PRIMARY KEY, `task_id` bigint unsigned NOT NULL,
 `chapter_key` varchar(80) NOT NULL, `session_key` varchar(100) NOT NULL,
 `sequence_no` int unsigned NOT NULL, `snapshot_json` mediumtext NOT NULL,
 UNIQUE KEY `uq_sequence` (`task_id`,`chapter_key`,`session_key`,`sequence_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
