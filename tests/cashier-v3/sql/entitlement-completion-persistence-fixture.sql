SET NAMES utf8mb4;

CREATE TABLE `eb_user_card_holder` (
  `id` bigint(20) unsigned NOT NULL,
  `uid` bigint(20) unsigned NOT NULL DEFAULT '0',
  `oid` bigint(20) unsigned NOT NULL DEFAULT '0',
  `card_name` varchar(128) NOT NULL DEFAULT '',
  `card_no` varchar(64) NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `product_type` int(11) unsigned NOT NULL DEFAULT '0',
  `write_times` int(11) unsigned NOT NULL DEFAULT '0',
  `write_surplus_times` int(11) unsigned NOT NULL DEFAULT '0',
  `write_start` int(11) unsigned NOT NULL DEFAULT '0',
  `write_end` int(11) unsigned NOT NULL DEFAULT '0',
  `is_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_uid_oid` (`uid`,`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `eb_store_order_cart_info` (
  `id` bigint(20) unsigned NOT NULL,
  `oid` bigint(20) unsigned NOT NULL DEFAULT '0',
  `cart_id` varchar(64) NOT NULL DEFAULT '',
  `product_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `cart_type` int(11) unsigned NOT NULL DEFAULT '0',
  `product_type` int(11) unsigned NOT NULL DEFAULT '0',
  `cart_info` mediumtext NOT NULL,
  `write_times` int(11) unsigned NOT NULL DEFAULT '0',
  `write_surplus_times` int(11) unsigned NOT NULL DEFAULT '0',
  `is_writeoff` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `write_start` int(11) unsigned NOT NULL DEFAULT '0',
  `write_end` int(11) unsigned NOT NULL DEFAULT '0',
  `pay_price` decimal(18,2) NOT NULL DEFAULT '0.00',
  `debt_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `repaid_debt_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `is_gift` tinyint(3) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_oid` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `eb_store_order` (
  `id` bigint(20) unsigned NOT NULL,
  `uid` bigint(20) unsigned NOT NULL DEFAULT '0',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `paid` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `is_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `is_system_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `is_user_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `refund_status` int(11) unsigned NOT NULL DEFAULT '0',
  `terminal_action` int(11) unsigned NOT NULL DEFAULT '0',
  `card_upgrade_use_oid` bigint(20) unsigned NOT NULL DEFAULT '0',
  `order_id` varchar(64) NOT NULL DEFAULT '',
  `mark` varchar(255) NOT NULL DEFAULT '',
  `pay_price` decimal(18,2) NOT NULL DEFAULT '0.00',
  `cash_pay_price` decimal(18,2) NOT NULL DEFAULT '0.00',
  `yue_pay_price` decimal(18,2) NOT NULL DEFAULT '0.00',
  `debt_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `repaid_debt_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `eb_store_reservation_order` (
  `id` bigint(20) unsigned NOT NULL,
  `cart_info_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `status` int(11) NOT NULL DEFAULT '0',
  `is_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `is_system_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_cart_info` (`cart_info_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `eb_store_debt` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `status` int(11) unsigned NOT NULL DEFAULT '0',
  `total_debt` decimal(18,2) NOT NULL DEFAULT '0.00',
  `repaid_debt` decimal(18,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
