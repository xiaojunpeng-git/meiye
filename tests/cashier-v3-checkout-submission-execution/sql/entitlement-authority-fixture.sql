SET NAMES utf8mb4;

-- Pre-migration legacy authorities required by the employee-type upgrade and
-- the production entitlement providers. Runtime business rows are seeded by
-- php/mysql-integration.php after MemberIntegrationFixture resets the database.
CREATE TABLE IF NOT EXISTS `eb_employee` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL DEFAULT '',
  `phone` varchar(32) NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `is_del` tinyint(1) NOT NULL DEFAULT 0,
  `add_time` int(11) unsigned NOT NULL DEFAULT 0,
  `update_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eb_system_store_staff` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `store_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `account` varchar(64) NOT NULL DEFAULT '',
  `staff_name` varchar(128) NOT NULL DEFAULT '',
  `roles` varchar(255) NOT NULL DEFAULT '',
  `level` int(11) NOT NULL DEFAULT 1,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `is_del` tinyint(1) NOT NULL DEFAULT 0,
  `is_fencheng` tinyint(1) NOT NULL DEFAULT 0,
  `is_hezuofang` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_employee_store` (`employee_id`,`store_id`,`is_del`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eb_system_menus` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pid` bigint(20) unsigned NOT NULL DEFAULT 0,
  `type` tinyint(2) NOT NULL DEFAULT 1,
  `icon` varchar(64) NOT NULL DEFAULT '',
  `menu_name` varchar(128) NOT NULL DEFAULT '',
  `module` varchar(64) NOT NULL DEFAULT '',
  `controller` varchar(128) NOT NULL DEFAULT '',
  `action` varchar(128) NOT NULL DEFAULT '',
  `api_url` varchar(255) NOT NULL DEFAULT '',
  `methods` varchar(32) NOT NULL DEFAULT '',
  `params` text,
  `sort` int(11) NOT NULL DEFAULT 0,
  `is_show` tinyint(1) NOT NULL DEFAULT 0,
  `is_show_path` tinyint(1) NOT NULL DEFAULT 0,
  `access` tinyint(1) NOT NULL DEFAULT 1,
  `menu_path` varchar(255) NOT NULL DEFAULT '',
  `path` varchar(255) NOT NULL DEFAULT '',
  `auth_type` tinyint(1) NOT NULL DEFAULT 1,
  `header` varchar(64) NOT NULL DEFAULT '',
  `is_header` tinyint(1) NOT NULL DEFAULT 0,
  `unique_auth` varchar(128) NOT NULL DEFAULT '',
  `is_del` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_unique_auth` (`unique_auth`,`is_del`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Global reservation authority allows cart_info_id=0 for an unpurchased
-- booking. This focused checkout uses C3 service_order, but the shared provider
-- schema must still be production-compatible.
CREATE TABLE IF NOT EXISTS `eb_store_reservation_order` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `cart_info_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `status` tinyint(2) NOT NULL DEFAULT 0,
  `is_del` tinyint(1) NOT NULL DEFAULT 0,
  `is_system_del` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_cart_info_id` (`cart_info_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- These recipe tables are existing legacy authorities and intentionally have
-- no canonical migration in the current checkout change set.
CREATE TABLE IF NOT EXISTS `eb_store_project_consumable_recipe` (
  `id` bigint(20) unsigned NOT NULL,
  `type` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `relation_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `project_product_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `project_unique` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `status` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `version` bigint(20) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_project` (`type`,`relation_id`,`project_product_id`,`project_unique`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `eb_store_project_consumable_recipe_detail` (
  `id` bigint(20) unsigned NOT NULL,
  `recipe_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `consumable_product_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `consumable_unique` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `qty_per_writeoff` decimal(18,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_recipe` (`recipe_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
