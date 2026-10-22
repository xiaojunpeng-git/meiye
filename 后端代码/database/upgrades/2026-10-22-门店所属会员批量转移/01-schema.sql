-- 批次与确认时会员名单分别保存；不修改订单、权益、余额或 store_user 关系。
CREATE TABLE IF NOT EXISTS `eb_user_store_transfer_batch` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(40) NOT NULL,
  `admin_id` int unsigned NOT NULL,
  `request_key` varchar(80) NOT NULL,
  `request_fingerprint` char(64) NOT NULL,
  `source_store_id` int unsigned NOT NULL,
  `target_store_id` int unsigned NOT NULL,
  `source_store_name` varchar(128) NOT NULL,
  `target_store_name` varchar(128) NOT NULL,
  `reason` varchar(200) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'queued',
  `total_count` int unsigned NOT NULL DEFAULT 0,
  `success_count` int unsigned NOT NULL DEFAULT 0,
  `skipped_count` int unsigned NOT NULL DEFAULT 0,
  `failed_count` int unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_no` (`batch_no`),
  UNIQUE KEY `uq_admin_request` (`admin_id`,`request_key`),
  KEY `idx_source_owner` (`source_store_id`,`admin_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='所属会员转移批次及执行进度';

CREATE TABLE IF NOT EXISTS `eb_user_store_transfer_batch_item` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(40) NOT NULL,
  `uid` int unsigned NOT NULL,
  `source_history_id` bigint unsigned NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `history_id` bigint unsigned NOT NULL DEFAULT 0,
  `result_reason` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_member` (`batch_no`,`uid`),
  KEY `idx_pending` (`batch_no`,`status`,`id`),
  KEY `idx_history` (`history_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='确认时冻结的会员及逐人调店结果';
