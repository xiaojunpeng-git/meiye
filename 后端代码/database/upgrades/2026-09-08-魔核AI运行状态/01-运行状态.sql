-- MOHE-AI-00002. Local source only; execute per-instance after approval and backup.
-- No conversation/question/prompt/answer columns. InnoDB is mandatory.
CREATE TABLE IF NOT EXISTS `eb_mohe_ai_mutex` (
  `instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `quarantined_slots` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`instance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_mohe_ai_attempt` (
  `instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `run_id` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attempt_code` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `kind` varchar(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `target_code` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_tokens` bigint unsigned NULL,
  `output_tokens` bigint unsigned NULL,
  `created_at` bigint unsigned NOT NULL,
  `expires_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`instance_id`,`run_id`,`attempt_code`),
  KEY `expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_mohe_ai_run` (
  `instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `run_id` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_id` bigint unsigned NOT NULL,
  `terminal` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `conversation_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `generation` bigint unsigned NOT NULL,
  `status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `progress_code` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `clarification_ref` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `version` bigint unsigned NOT NULL,
  `created_at` bigint unsigned NOT NULL,
  `expires_at` bigint unsigned NOT NULL,
  `deadline_at` bigint unsigned NOT NULL,
  `last_clock_at` bigint unsigned NOT NULL,
  `remaining_ms` int unsigned NOT NULL,
  `pause_at` bigint unsigned NOT NULL,
  `clarification_count` tinyint unsigned NOT NULL,
  `slot_held` tinyint unsigned NOT NULL,
  `worker_token` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `evidence_ref` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `answer_ref` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `snapshot_json` text NOT NULL,
  `counters_json` text NOT NULL,
  PRIMARY KEY (`instance_id`,`run_id`),
  KEY `account_activity` (`instance_id`,`account_id`,`terminal`,`status`),
  KEY `expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_mohe_ai_receipt` (
  `instance_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `receipt_key` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `window_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `run_id` varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`instance_id`,`receipt_key`),
  KEY `run_lookup` (`instance_id`,`run_id`),
  KEY `expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
