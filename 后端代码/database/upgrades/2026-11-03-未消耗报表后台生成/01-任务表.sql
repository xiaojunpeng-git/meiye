-- 未消耗报表只保存查询任务状态；权益、余额及核销事实仍由现有权威读取。
-- 每个实例独立执行；实际报表行保存在站点私有 runtime，过期后清理。
CREATE TABLE IF NOT EXISTS `eb_basic_unconsumed_task` (
  `task_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `report_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `actor_key` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_ids_json` text NOT NULL,
  `operator_id` int unsigned NOT NULL,
  `permission_version` varchar(128) NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `error_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `metadata_json` mediumtext NULL,
  `created_at` int unsigned NOT NULL,
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `completed_at` int unsigned NOT NULL DEFAULT 0,
  `expires_at` int unsigned NOT NULL,
  PRIMARY KEY (`task_id`),
  KEY `idx_expiry` (`expires_at`),
  KEY `idx_actor_status` (`actor_key`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
