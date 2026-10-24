-- MYDX-P1-03：学习上报事件去重，位置与已确认覆盖分别保存。
CREATE TABLE IF NOT EXISTS `eb_growth_learning_event` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `task_id` bigint unsigned NOT NULL, `chapter_key` varchar(80) NOT NULL,
 `session_key` varchar(100) NOT NULL, `event_key` varchar(128) NOT NULL,
 `position` int unsigned NOT NULL, `received_at` int unsigned NOT NULL,
 UNIQUE KEY `uq_event` (`task_id`,`chapter_key`,`session_key`,`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
