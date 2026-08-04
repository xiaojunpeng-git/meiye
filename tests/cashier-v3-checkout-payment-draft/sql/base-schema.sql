SET NAMES utf8mb4;

-- Minimal Gateway workspace-version authority used by the sequential payment
-- draft flow. Checkout aggregate tables are always created from the canonical
-- production migrations by mysql56-matrix.sh.
CREATE TABLE `eb_cashier_v3_resource_version` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `scope_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `resource_kind` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `resource_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `current_version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `last_action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `add_time` int(11) unsigned NOT NULL DEFAULT '0',
  `update_time` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scope_resource` (`scope_type`,`scope_id`,`resource_kind`,`resource_id`),
  KEY `idx_scope_kind` (`scope_type`,`scope_id`,`resource_kind`),
  KEY `idx_update_time` (`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The SKU migration also upgrades the formal sales-line table. The payment
-- draft matrix does not write sales orders, but needs this minimal predecessor
-- shape so the canonical migration sequence can run unchanged.
CREATE TABLE `eb_cashier_v3_sales_order_line` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
