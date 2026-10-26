CREATE TABLE `eb_user` (
  `uid` bigint(20) unsigned NOT NULL,
  `now_money` decimal(12,2) NOT NULL DEFAULT '0.00',
  `ben_money` decimal(12,2) NOT NULL DEFAULT '0.00',
  `give_money` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `is_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `delete_time` int(11) unsigned DEFAULT NULL,
  `belong_store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `eb_user_money` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uid` bigint(20) unsigned NOT NULL,
  `link_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `type` varchar(64) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `number` decimal(12,2) NOT NULL DEFAULT '0.00',
  `balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `mark` varchar(255) NOT NULL DEFAULT '',
  `pm` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `ben_money` decimal(12,2) NOT NULL DEFAULT '0.00',
  `give_money` decimal(12,2) NOT NULL DEFAULT '0.00',
  `add_time` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_uid_time` (`uid`,`add_time`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `eb_balance_test_barrier` (
  `barrier_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `worker_token` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `released` tinyint(3) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`barrier_id`,`worker_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
