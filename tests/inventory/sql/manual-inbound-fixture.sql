CREATE TABLE IF NOT EXISTS `eb_store_product` (
  `id` bigint(20) unsigned NOT NULL,
  `type` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `relation_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `is_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `is_inventory` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `store_name` varchar(255) NOT NULL DEFAULT '',
  `code` varchar(64) NOT NULL DEFAULT '',
  `bar_code` varchar(64) NOT NULL DEFAULT '',
  `salon_stock_enabled` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `sort` int(11) NOT NULL DEFAULT '0',
  `keyword` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_system_store` (
  `id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT '',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `is_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `is_show` tinyint(3) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_system_store_staff` (
  `id` bigint(20) unsigned NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `is_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_store_status` (`store_id`,`status`,`is_del`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_organization` (
  `id` bigint(20) unsigned NOT NULL,
  `pid` bigint(20) unsigned NOT NULL DEFAULT '0',
  `name` varchar(100) NOT NULL DEFAULT '',
  `is_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_organization_store` (
  `store_id` bigint(20) unsigned NOT NULL,
  `org_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Current inventory upgrades register platform routes even in the isolated test schema.
CREATE TABLE IF NOT EXISTS `eb_system_menus` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pid` bigint(20) unsigned NOT NULL DEFAULT '0', `type` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `icon` varchar(100) NOT NULL DEFAULT '', `menu_name` varchar(100) NOT NULL DEFAULT '',
  `module` varchar(100) NOT NULL DEFAULT '', `controller` varchar(100) NOT NULL DEFAULT '',
  `action` varchar(100) NOT NULL DEFAULT '', `api_url` varchar(255) NOT NULL DEFAULT '',
  `methods` varchar(16) NOT NULL DEFAULT '', `params` varchar(255) NOT NULL DEFAULT '',
  `sort` int(11) NOT NULL DEFAULT '0', `is_show` tinyint(3) NOT NULL DEFAULT '0',
  `is_show_path` tinyint(3) NOT NULL DEFAULT '0', `access` tinyint(3) NOT NULL DEFAULT '0',
  `menu_path` varchar(255) NOT NULL DEFAULT '', `path` varchar(255) NOT NULL DEFAULT '',
  `auth_type` tinyint(3) NOT NULL DEFAULT '0', `header` varchar(100) NOT NULL DEFAULT '',
  `is_header` tinyint(3) NOT NULL DEFAULT '0', `unique_auth` varchar(255) NOT NULL DEFAULT '',
  `is_del` tinyint(3) NOT NULL DEFAULT '0', PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Empty category fixtures let catalog pagination run without inventing product classifications.
CREATE TABLE IF NOT EXISTS `eb_store_product_category` (
  `id` bigint(20) unsigned NOT NULL, `cate_name` varchar(100) NOT NULL DEFAULT '',
  `pid` bigint(20) unsigned NOT NULL DEFAULT '0', `is_show` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `sort` int(11) NOT NULL DEFAULT '0', PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_store_product_relation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `product_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `relation_id` bigint(20) unsigned NOT NULL DEFAULT '0', `type` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1', PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_store_product_attr_value` (
  `id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `type` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `unique` varchar(64) NOT NULL DEFAULT '',
  `suk` varchar(255) NOT NULL DEFAULT '',
  `bar_code` varchar(64) NOT NULL DEFAULT '',
  `code` varchar(64) NOT NULL DEFAULT '',
  `stock_unit` varchar(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_unique` (`product_id`,`unique`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `eb_store_product`
  (`id`,`type`,`relation_id`,`is_del`,`is_inventory`,`store_name`,`code`,`bar_code`,`salon_stock_enabled`,`sort`,`keyword`)
VALUES
  (990001,1,99001,0,1,'TEST-库存入库修护精华','TEST-P-990001','6909900100012',0,1,'TEST-库存入库修护精华'),
  (990041,1,99004,0,1,'TEST-回滚验证商品','TEST-P-990041','6909900100043',0,1,'TEST-回滚验证商品'),
  (990061,1,99006,0,1,'TEST-FEFO出库精华','TEST-P-990061','6909900100067',0,1,'TEST-FEFO出库精华');

INSERT IGNORE INTO `eb_store_product_attr_value`
  (`id`,`product_id`,`type`,`unique`,`suk`,`bar_code`,`code`,`stock_unit`)
VALUES
  (990011,990001,0,'testsku990011','100ml','6909900100012','TEST-S-990011','瓶'),
  (9900411,990041,0,'testsku9900411','50ml','6909900100043','TEST-S-990041','瓶'),
  (9900611,990061,0,'testsku9900611','30ml','6909900100067','TEST-S-990061','瓶');

INSERT IGNORE INTO `eb_system_store` (`id`,`name`,`is_del`,`is_show`) VALUES
  (99001,'TEST-库存门店一',0,1),
  (99002,'TEST-库存门店二',0,1),
  (99003,'TEST-库存门店三',0,1),
  (99004,'TEST-库存门店四',0,1),
  (99005,'TEST-库存门店五',0,1),
  (99006,'TEST-FEFO出库门店',0,1);

INSERT IGNORE INTO `eb_system_store_staff` (`id`,`store_id`,`status`,`is_del`) VALUES
  (990001,99001,1,0),
  (990002,99002,1,0),
  (990003,99003,1,0),
  (990004,99004,1,0),
  (990005,99005,1,0),
  (990006,99006,1,0);

INSERT IGNORE INTO `eb_organization` (`id`,`pid`,`name`,`is_del`) VALUES
  (99000,0,'TEST-库存总部',0),
  (99001,99000,'TEST-库存组织一',0),
  (99002,99000,'TEST-库存组织二',0),
  (99003,99000,'TEST-库存组织三',0),
  (99004,99000,'TEST-库存组织四',0),
  (99005,99000,'TEST-库存组织五',0),
  (99006,99000,'TEST-FEFO出库组织',0);

INSERT IGNORE INTO `eb_organization_store` (`store_id`,`org_id`) VALUES
  (99001,99001),
  (99002,99002),
  (99003,99003),
  (99004,99004),
  (99005,99005),
  (99006,99006);
