SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_user` (
  `uid` int unsigned NOT NULL AUTO_INCREMENT,
  `nickname` varchar(60) NOT NULL DEFAULT '',
  `real_name` varchar(25) NOT NULL DEFAULT '',
  `phone` char(15) NOT NULL DEFAULT '',
  `bar_code` varchar(32) NOT NULL DEFAULT '',
  `avatar` varchar(256) NOT NULL DEFAULT '',
  `user_type` varchar(32) NOT NULL DEFAULT '',
  `belong_store_id` int NOT NULL DEFAULT 0,
  `now_money` decimal(12,2) NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `is_del` tinyint NOT NULL DEFAULT 0,
  `delete_time` timestamp NULL DEFAULT NULL,
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `extend_info` longtext,
  `sex` tinyint NOT NULL DEFAULT 0,
  `birthday` int NOT NULL DEFAULT 0,
  `card_id` varchar(20) NOT NULL DEFAULT '',
  `addres` varchar(255) NOT NULL DEFAULT '',
  `mark` varchar(255) NOT NULL DEFAULT '',
  `adminid` int unsigned NOT NULL DEFAULT 0,
  `level` int NOT NULL DEFAULT 0,
  `exp` decimal(12,2) NOT NULL DEFAULT 0,
  `level_status` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`uid`),
  KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The production checkout guard requires the authoritative balance ledger to
-- exist even when this sale-only fixture pays entirely by bookkeeping receipt.
-- Keep the same idempotency columns as the canonical balance migrations so a
-- later balance-payment branch cannot pass against an incomplete test schema.
CREATE TABLE IF NOT EXISTS `eb_user_money` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(10) unsigned NOT NULL DEFAULT 0,
  `link_id` varchar(32) NOT NULL DEFAULT '0',
  `type` varchar(64) NOT NULL DEFAULT '',
  `title` varchar(64) NOT NULL DEFAULT '',
  `number` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `balance` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `pm` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `mark` varchar(512) NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `add_time` int(11) unsigned NOT NULL DEFAULT 0,
  `ben_money` decimal(10,2) NOT NULL DEFAULT '0.00',
  `give_money` decimal(10,2) NOT NULL DEFAULT '0.00',
  `ben_change_amount` decimal(12,2) DEFAULT NULL,
  `give_change_amount` decimal(12,2) DEFAULT NULL,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `idempotency_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_idempotency_key` (`idempotency_key`),
  KEY `idx_uid` (`uid`),
  KEY `idx_type_link` (`type`,`link_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eb_store_product` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pid` bigint(20) unsigned NOT NULL DEFAULT 0,
  `type` tinyint NOT NULL DEFAULT 1,
  `relation_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `product_type` tinyint NOT NULL DEFAULT 0,
  `store_name` varchar(128) NOT NULL DEFAULT '',
  `cate_id` varchar(255) NOT NULL DEFAULT '',
  `keyword` varchar(255) NOT NULL DEFAULT '',
  `unit_name` varchar(32) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `is_show` tinyint NOT NULL DEFAULT 1,
  `is_del` tinyint NOT NULL DEFAULT 0,
  `is_verify` tinyint NOT NULL DEFAULT 1,
  `is_inventory` tinyint NOT NULL DEFAULT 0,
  `allow_negative_stock` tinyint NOT NULL DEFAULT 1,
  `card_num` int NOT NULL DEFAULT 0,
  `card_num_type` tinyint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_store_catalog` (`relation_id`,`type`,`is_del`,`is_show`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eb_store_product_attr_value` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `product_type` tinyint NOT NULL DEFAULT 0,
  `unique` varchar(20) NOT NULL DEFAULT '',
  `suk` varchar(128) NOT NULL DEFAULT '',
  `price` decimal(12,2) unsigned NOT NULL DEFAULT 0,
  `ot_price` decimal(12,2) unsigned NOT NULL DEFAULT 0,
  `stock` decimal(18,4) NOT NULL DEFAULT 0,
  `code` varchar(50) NOT NULL DEFAULT '',
  `bar_code` varchar(50) NOT NULL DEFAULT '',
  `is_show` tinyint NOT NULL DEFAULT 1,
  `type` tinyint NOT NULL DEFAULT 0,
  `write_times` int NOT NULL DEFAULT 0,
  `write_valid` tinyint NOT NULL DEFAULT 1,
  `write_days` int NOT NULL DEFAULT 0,
  `write_start` int NOT NULL DEFAULT 0,
  `write_end` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_product_sku` (`product_id`,`id`),
  KEY `idx_unique_sku` (`unique`,`product_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eb_store_product_category` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cate_name` varchar(128) NOT NULL DEFAULT '',
  `type` tinyint NOT NULL DEFAULT 0,
  `relation_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `is_show` tinyint NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eb_store_card_related` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `card_product_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `product_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `product_type` tinyint NOT NULL DEFAULT 0,
  `product_attr_unique` varchar(20) NOT NULL DEFAULT '',
  `cost` decimal(12,2) unsigned NOT NULL DEFAULT 0,
  `price` decimal(12,2) unsigned NOT NULL DEFAULT 0,
  `write_times` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
