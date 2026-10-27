-- MYDX-P1-01：独立成长事实与请求回执；默认关闭，不修改旧业务或员工主档。
CREATE TABLE IF NOT EXISTS `eb_growth_setting` (
 `id` int unsigned NOT NULL PRIMARY KEY,
 `enabled` tinyint unsigned NOT NULL DEFAULT 0,
 `business_link_enabled` tinyint unsigned NOT NULL DEFAULT 0,
 `templates_json` mediumtext NOT NULL,
 `updated_by` int unsigned NOT NULL DEFAULT 0,
 `updated_at` int unsigned NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `eb_growth_setting` (`id`,`templates_json`) VALUES (1,'[]');
CREATE TABLE IF NOT EXISTS `eb_growth_receipt` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `actor_key` varchar(64) NOT NULL,
 `action` varchar(80) NOT NULL,
 `request_key` varchar(128) NOT NULL,
 `request_hash` char(64) NOT NULL,
 `response_json` mediumtext NOT NULL,
 `created_at` int unsigned NOT NULL,
 UNIQUE KEY `uq_request` (`actor_key`,`action`,`request_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_event` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `event_type` varchar(64) NOT NULL,
 `source_type` varchar(40) NOT NULL,
 `source_id` bigint unsigned NOT NULL,
 `employee_id` int unsigned NOT NULL DEFAULT 0,
 `organization_id` int unsigned NOT NULL DEFAULT 0,
 `store_id` int unsigned NOT NULL DEFAULT 0,
 `actor_key` varchar(64) NOT NULL,
 `business_date` date NOT NULL,
 `occurred_at` int unsigned NOT NULL,
 `settled_at` int unsigned NOT NULL,
 `recorded_at` int unsigned NOT NULL,
 `payload_json` mediumtext NOT NULL,
 `delivery_state` varchar(16) NOT NULL DEFAULT 'PENDING',
 UNIQUE KEY `uq_event` (`event_type`,`source_type`,`source_id`),
 KEY `ix_pending` (`delivery_state`,`id`),
 KEY `ix_person_time` (`employee_id`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
