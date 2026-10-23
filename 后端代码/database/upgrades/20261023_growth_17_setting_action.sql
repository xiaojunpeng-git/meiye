-- MYDX-P1-17：每次启停是独立操作，事件引用操作ID，不能复用设置单行ID导致第二次启停失败。
CREATE TABLE IF NOT EXISTS `eb_growth_setting_action` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `enabled_before` tinyint unsigned NOT NULL, `enabled_after` tinyint unsigned NOT NULL,
 `unfinished_tasks` int unsigned NOT NULL, `reason` varchar(1000) NOT NULL,
 `actor_key` varchar(64) NOT NULL, `created_at` int unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
