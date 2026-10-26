-- upgrade_key: 20260816-005-cashier-v3-presale-claim-outbound
-- MySQL 5.6 compatible, repeatable and additive only.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_presale_claimable_line` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `claimable_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_path_snapshot` varchar(512) NOT NULL DEFAULT '',
  `organization_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL,
  `store_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `source_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_order_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `source_order_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `member_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `member_phone_snapshot` varchar(32) NOT NULL DEFAULT '',
  `product_id` bigint(20) unsigned NOT NULL,
  `sku_id` bigint(20) unsigned NOT NULL,
  `sku_unique_snapshot` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `product_name_snapshot` varchar(255) NOT NULL DEFAULT '',
  `quantity` bigint(20) unsigned NOT NULL,
  `claimed_quantity` bigint(20) unsigned NOT NULL DEFAULT '0',
  `claim_status` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `close_operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `closed_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `business_date` date NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_claimable_line` (`tenant_id`,`claimable_line_id`),
  UNIQUE KEY `uk_tenant_source_line` (`tenant_id`,`source_order_line_id`),
  KEY `idx_scope_status_date` (`tenant_id`,`store_id`,`claim_status`,`business_date`,`id`),
  KEY `idx_org_status_date` (`tenant_id`,`organization_id`,`claim_status`,`business_date`,`id`),
  KEY `idx_order_line` (`tenant_id`,`source_order_id`,`source_order_line_id`,`id`),
  KEY `idx_member` (`tenant_id`,`member_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 presale sales lines eligible for repeated claims';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_presale_claim` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `claim_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `claimable_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `claim_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `quantity` bigint(20) unsigned NOT NULL,
  `claim_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operator_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `void_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `void_reason_snapshot` varchar(500) NOT NULL DEFAULT '',
  `void_operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `voided_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  `business_date` date NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_claim_id` (`tenant_id`,`claim_id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key`),
  UNIQUE KEY `uk_tenant_claim_no` (`tenant_id`,`claim_no`),
  KEY `idx_claimable_status` (`tenant_id`,`claimable_line_id`,`claim_status`,`id`),
  KEY `idx_scope_date` (`tenant_id`,`store_id`,`business_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 append-only presale claim documents';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_presale_claim_batch` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `claim_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `claim_line_id` bigint(20) unsigned NOT NULL,
  `movement_fact_id` bigint(20) unsigned NOT NULL,
  `stock_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned NOT NULL,
  `quantity_units` bigint(20) unsigned NOT NULL,
  `quantity_scale` tinyint(3) unsigned NOT NULL,
  `unit_cost_cents` bigint(20) NOT NULL,
  `cost_amount_cents` bigint(20) NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_movement_fact` (`tenant_id`,`movement_fact_id`),
  KEY `idx_claim` (`tenant_id`,`claim_id`,`id`),
  KEY `idx_batch` (`tenant_id`,`batch_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable batch allocations for cashier v3 presale claims';

-- The platform page is an existing inventory capability.  Register routes
-- against existing view/manage grants; do not automatically assign either
-- grant to any role.
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台预售领用列表','','','','product/v3/presale-claims','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-batch-view',0
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0
    AND `api_url`='product/v3/presale-claims' AND `methods`='GET'
);

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台预售领用明细','','','','product/v3/presale-claims/:id','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-batch-view',0
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0
    AND `api_url`='product/v3/presale-claims/:id' AND `methods`='GET'
);

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台预售领用出库','','','','product/v3/presale-claims/claim','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0
    AND `api_url`='product/v3/presale-claims/claim' AND `methods`='POST'
);

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台预售领用作废','','','','product/v3/presale-claims/:id/void','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0
    AND `api_url`='product/v3/presale-claims/:id/void' AND `methods`='POST'
);

SET @presale_inventory_parent_id := (
  SELECT `id` FROM `eb_system_menus`
  WHERE `type`=1 AND `is_del`=0 AND `unique_auth`='admin-stock-manage'
  ORDER BY `id` LIMIT 1
);

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @presale_inventory_parent_id,1,'','预售领用','admin','','','','','[]',89,1,0,1,
       '/admin/stock/presale-claim',CONCAT('7/', @presale_inventory_parent_id),1,'',0,'admin-stock-manage',0
FROM DUAL WHERE @presale_inventory_parent_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0
      AND `menu_path`='/admin/stock/presale-claim'
  );

SELECT 'APPLY_OK' AS apply_result;
