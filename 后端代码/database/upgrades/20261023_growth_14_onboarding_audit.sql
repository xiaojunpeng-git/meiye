-- MYDX-P1-14：组织直属员工首次归属与门店首次任职分流，失败重试记录不可覆盖。
CREATE TABLE IF NOT EXISTS `eb_growth_onboarding_org_cursor` (
 `rule_id` bigint unsigned NOT NULL PRIMARY KEY, `last_org_employee_id` bigint unsigned NOT NULL,
 `enabled_since` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_growth_onboarding_attempt` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `dispatch_id` bigint unsigned NOT NULL,
 `rule_id` bigint unsigned NOT NULL, `source_event_key` varchar(128) NOT NULL,
 `state` varchar(20) NOT NULL, `failure_reason` varchar(255) NOT NULL DEFAULT '',
 `created_at` int unsigned NOT NULL, KEY `ix_dispatch` (`dispatch_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
