-- MYDX-P1-16：分配时任职维度属于任务唯一事实的子记录，不随调岗重写历史。
-- 既有任务不从当前人事资料猜测回填；未知岗位保持未知，避免历史归属漂移。
CREATE TABLE IF NOT EXISTS `eb_growth_task_job` (
 `task_id` bigint unsigned NOT NULL, `job_id` int unsigned NOT NULL,
 `position_id` int unsigned NOT NULL, `position_name` varchar(180) NOT NULL,
 PRIMARY KEY (`task_id`,`job_id`), KEY `ix_position` (`position_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
