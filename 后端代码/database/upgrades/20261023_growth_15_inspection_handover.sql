-- MYDX-P1-15：巡检执行/复核职责交接留痕，不修改已正式检查或复查的历史人员。
CREATE TABLE IF NOT EXISTS `eb_growth_inspection_action` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `inspection_id` bigint unsigned NOT NULL,
 `action` varchar(32) NOT NULL, `reason` text NOT NULL, `before_json` mediumtext NOT NULL,
 `after_json` mediumtext NOT NULL, `actor_key` varchar(64) NOT NULL, `created_at` int unsigned NOT NULL,
 KEY `ix_inspection` (`inspection_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
