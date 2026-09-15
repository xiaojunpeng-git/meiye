-- MOHE-AI-00004. Execute per customer instance only after backup and the
-- dedicated-worker release barrier. No business fact, prompt or answer data.
CREATE TABLE IF NOT EXISTS `eb_mohe_ai_execution_worker` (
  `instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `worker_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `host_name` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `process_id` int unsigned NOT NULL,
  `heartbeat_at` bigint unsigned NOT NULL,
  `expires_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`instance_id`,`worker_id`),
  KEY `liveness` (`instance_id`,`heartbeat_at`,`expires_at`),
  KEY `expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verify the table uses InnoDB and the two indexes above before enabling
-- MOHE_AI.EXECUTION_ENABLED. This migration does not start a Worker.
