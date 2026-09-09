-- Local/instance-specific migration only after target and backup checks. No existing facts touched.
-- Prefix eb_ must be adapted by the controlled installer for the target instance.
CREATE TABLE IF NOT EXISTS `eb_mohe_ai_management_state` (
 `instance_key` varchar(128) NOT NULL,
 `revision` bigint unsigned NOT NULL,
 `active_version` varchar(40) NOT NULL,
 `draft_json` mediumtext NOT NULL,
 `updated_at` bigint unsigned NOT NULL,
 PRIMARY KEY (`instance_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_mohe_ai_management_version` (
 `instance_key` varchar(128) NOT NULL,
 `version` varchar(40) NOT NULL,
 `document_json` mediumtext NOT NULL,
 `document_hash` char(64) NOT NULL,
 `created_at` bigint unsigned NOT NULL,
 `action` varchar(16) NOT NULL,
 `source_version` varchar(40) NOT NULL,
 PRIMARY KEY (`instance_key`,`version`),
 KEY `management_versions_created` (`instance_key`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
