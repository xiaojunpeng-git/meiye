SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_sales_order` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_sales_order_line` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_checkout_line_draft` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eb_store_product` (
  `id` bigint(20) unsigned NOT NULL,
  `type` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `relation_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `product_type` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `is_inventory` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `is_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `is_show` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `is_verify` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `store_name` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eb_store_product_attr_value` (
  `id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `unique` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `type` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `is_show` tinyint(3) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_unique` (`product_id`,`unique`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `eb_store_product`
  (`id`,`type`,`relation_id`,`product_type`,`is_inventory`,`is_del`,`is_show`,`is_verify`,`store_name`)
VALUES
  (501,1,7,0,1,0,1,1,'批次库存产品'),
  (502,1,7,0,1,0,1,1,'库存不足产品'),
  (503,1,7,0,1,0,1,1,'零库存产品'),
  (504,1,7,0,0,0,1,1,'普通非库存产品'),
  (505,1,7,0,1,0,1,1,'同编码库存产品');

INSERT INTO `eb_store_product_attr_value`
  (`id`,`product_id`,`unique`,`type`,`is_show`)
VALUES
  (601,501,'SKU-501',0,1),
  (602,502,'SKU-502',0,1),
  (603,503,'SKU-503',0,1),
  (604,504,'SKU-504',0,1),
  (605,505,'SKU-501',0,1);
