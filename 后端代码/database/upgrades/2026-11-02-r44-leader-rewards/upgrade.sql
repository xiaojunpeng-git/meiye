-- 第44轮：配置、开团快照和逐份履约回执；不回填旧团，不修改销售/收款事实。
CREATE TABLE IF NOT EXISTS `eb_combination_leader_reward_config` (
  `activity_id` int unsigned NOT NULL,
  `config_json` mediumtext NOT NULL,
  `admin_id` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`activity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拼团团长奖励配置';

CREATE TABLE IF NOT EXISTS `eb_combination_leader_reward` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL,
  `activity_id` int unsigned NOT NULL,
  `leader_order_id` int unsigned NOT NULL,
  `member_id` int unsigned NOT NULL,
  `store_id` int unsigned NOT NULL DEFAULT 0,
  `admin_id` int unsigned NOT NULL DEFAULT 0,
  `config_snapshot` mediumtext NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'waiting',
  `cancel_reason` varchar(255) NOT NULL DEFAULT '',
  `completed_at` int unsigned NOT NULL DEFAULT 0,
  `created_at` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uniq_group` (`group_id`),
  KEY `idx_pending` (`status`,`id`), KEY `idx_order` (`leader_order_id`),
  KEY `idx_activity_scope` (`activity_id`,`store_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='开团冻结的团长奖励与可靠履约队列';

CREATE TABLE IF NOT EXISTS `eb_combination_leader_reward_item` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reward_id` bigint unsigned NOT NULL,
  `item_no` int unsigned NOT NULL,
  `item_snapshot` text NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `gift_id` varchar(64) NOT NULL DEFAULT '',
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uniq_reward_item` (`reward_id`,`item_no`),
  KEY `idx_pending` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='逐份奖励回执，部分已使用不阻碍撤回其他未使用奖励';
