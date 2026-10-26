-- Persistent service settings only. NO conversation/message/prompt columns.
-- Apply only to an explicitly selected local/approved instance; prefix must match.
CREATE TABLE IF NOT EXISTS `eb_mohe_ai_config` (
  `instance_id` varchar(128) NOT NULL,
  `enabled` tinyint NOT NULL DEFAULT 0,
  `model` varchar(128) NOT NULL,
  `encrypted_key` text NOT NULL,
  `external_authorized` tinyint NOT NULL DEFAULT 0,
  `version` bigint NOT NULL,
  PRIMARY KEY (`instance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
