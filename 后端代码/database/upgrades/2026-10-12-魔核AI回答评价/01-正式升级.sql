-- 用户明确评价后才写入问答快照；每个实例及运行记录最多一条。
CREATE TABLE IF NOT EXISTS `eb_mohe_ai_feedback` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `run_id` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `generation` bigint unsigned NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `terminal` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `rating` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `question` mediumtext NOT NULL,
  `answer_json` mediumtext NOT NULL,
  `answer_summary` mediumtext NOT NULL,
  `run_status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `run_reason` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `model_version` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `run_created_at` bigint unsigned NOT NULL,
  `created_at` bigint unsigned NOT NULL,
  `updated_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instance_run` (`instance_id`,`run_id`),
  KEY `instance_updated` (`instance_id`,`updated_at`,`id`),
  KEY `instance_rating_updated` (`instance_id`,`rating`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
