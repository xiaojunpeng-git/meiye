-- upgrade_key: 20260802-003-inventory-v3-cross-subject-transfer-receipt
SET NAMES utf8mb4;

SET @has_supply_party_type := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND column_name='supply_party_type');
SET @supply_party_type_sql := IF(@has_supply_party_type=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD COLUMN `supply_party_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''UNSPECIFIED'' AFTER `store_id`', 'SELECT 1');
PREPARE stmt FROM @supply_party_type_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_supply_party_id := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND column_name='supply_party_id');
SET @supply_party_id_sql := IF(@has_supply_party_id=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD COLUMN `supply_party_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `supply_party_type`', 'SELECT 1');
PREPARE stmt FROM @supply_party_id_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_supply_party_name := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND column_name='supply_party_name_snapshot');
SET @supply_party_name_sql := IF(@has_supply_party_name=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD COLUMN `supply_party_name_snapshot` varchar(120) NOT NULL DEFAULT '''' AFTER `supply_party_id`', 'SELECT 1');
PREPARE stmt FROM @supply_party_name_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_supply_party_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND index_name='idx_supply_party_status');
SET @supply_party_index_sql := IF(@has_supply_party_index=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD KEY `idx_supply_party_status` (`tenant_id`,`supply_party_type`,`supply_party_id`,`document_status`,`id`)', 'SELECT 1');
PREPARE stmt FROM @supply_party_index_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- One default headquarters warehouse is owned by each active organization root.
-- It is master data only; opening stock enters through the platform HQ inbound
-- command and writes the same V3 batch facts as a store inbound.
INSERT IGNORE INTO `eb_inventory_location`
  (`tenant_id`,`organization_id`,`organization_path`,`organization_name_snapshot`,`location_type`,
   `owner_id`,`location_code`,`location_name`,`store_id`,`store_name_snapshot`,`is_default`,
   `location_status`,`version`,`created_at`,`updated_at`)
SELECT DISTINCT l.tenant_id,root.id,CONCAT('/',root.id,'/'),root.name,'HQ',
       root.id,CONCAT('HQ-',root.id),'总部仓',0,'',1,'ACTIVE',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `eb_inventory_location` l
JOIN `eb_organization` root ON root.id=CAST(SUBSTRING_INDEX(TRIM(BOTH '/' FROM l.organization_path),'/',1) AS UNSIGNED)
WHERE root.pid=0 AND root.status=1 AND root.is_del=0 AND l.location_type='STORE' AND l.location_status='ACTIVE';

CREATE TABLE IF NOT EXISTS `eb_inventory_cross_transfer_document` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_no` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `from_party_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `from_party_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `from_party_name_snapshot` varchar(120) NOT NULL DEFAULT '',
  `from_location_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `to_party_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `to_party_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `to_party_name_snapshot` varchar(120) NOT NULL DEFAULT '',
  `to_location_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `request_document_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `initiator_store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `created_by_operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `dispatched_by_operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `received_by_operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `document_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'DRAFT',
  `remark` varchar(500) NOT NULL DEFAULT '', `business_date` date NOT NULL,
  `dispatched_at` int(11) unsigned NOT NULL DEFAULT '0', `received_at` int(11) unsigned NOT NULL DEFAULT '0', `cancelled_at` int(11) unsigned NOT NULL DEFAULT '0', `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key`), UNIQUE KEY `uk_tenant_transfer_no` (`tenant_id`,`transfer_no`),
  KEY `idx_from_status_date` (`tenant_id`,`from_location_id`,`document_status`,`business_date`,`id`), KEY `idx_to_status_date` (`tenant_id`,`to_location_id`,`document_status`,`business_date`,`id`), KEY `idx_request` (`request_document_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cross-subject inventory transfer authority with in-transit receipt';

CREATE TABLE IF NOT EXISTS `eb_inventory_cross_transfer_line` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `document_id` bigint(20) unsigned NOT NULL DEFAULT '0', `line_no` int(10) unsigned NOT NULL DEFAULT '0',
  `request_line_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `from_product_id` bigint(20) unsigned NOT NULL DEFAULT '0', `from_sku_id` bigint(20) unsigned NOT NULL DEFAULT '0', `from_sku_unique` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `to_product_id` bigint(20) unsigned NOT NULL DEFAULT '0', `to_sku_id` bigint(20) unsigned NOT NULL DEFAULT '0', `to_sku_unique` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `requested_quantity_units` bigint(20) unsigned NOT NULL DEFAULT '0', `quantity_scale` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `product_name_snapshot` varchar(120) NOT NULL DEFAULT '', `sku_name_snapshot` varchar(120) NOT NULL DEFAULT '', `stock_unit_snapshot` varchar(32) NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`), UNIQUE KEY `uk_document_line_no` (`document_id`,`line_no`), KEY `idx_document_request_line` (`document_id`,`request_line_id`), KEY `idx_document_from_product` (`document_id`,`from_product_id`,`from_sku_id`), KEY `idx_document_to_product` (`document_id`,`to_product_id`,`to_sku_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cross-subject transfer requested product line';

CREATE TABLE IF NOT EXISTS `eb_inventory_cross_transfer_batch_allocation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `document_id` bigint(20) unsigned NOT NULL DEFAULT '0', `line_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `from_stock_id` bigint(20) unsigned NOT NULL DEFAULT '0', `to_stock_id` bigint(20) unsigned NOT NULL DEFAULT '0', `from_batch_id` bigint(20) unsigned NOT NULL DEFAULT '0', `to_batch_id` bigint(20) unsigned NOT NULL DEFAULT '0', `origin_batch_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `quantity_units` bigint(20) unsigned NOT NULL DEFAULT '0', `unit_cost_cents` bigint(20) unsigned NOT NULL DEFAULT '0', `quantity_scale` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `dispatched_at` int(11) unsigned NOT NULL DEFAULT '0', `received_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`), UNIQUE KEY `uk_document_source_batch` (`document_id`,`from_batch_id`), KEY `idx_line` (`line_id`,`id`), KEY `idx_origin` (`origin_batch_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cross-subject transfer FEFO batch allocation and lineage';

CREATE TABLE IF NOT EXISTS `eb_inventory_stock_request_fulfillment` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `request_document_id` bigint(20) unsigned NOT NULL DEFAULT '0', `request_line_id` bigint(20) unsigned NOT NULL DEFAULT '0', `transfer_document_id` bigint(20) unsigned NOT NULL DEFAULT '0', `transfer_line_id` bigint(20) unsigned NOT NULL DEFAULT '0', `fulfilled_quantity_units` bigint(20) unsigned NOT NULL DEFAULT '0', `received_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`), UNIQUE KEY `uk_transfer_line` (`transfer_line_id`), KEY `idx_request_line` (`request_document_id`,`request_line_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='received cross-transfer fulfillment for inventory request lines';

-- These HQ commands share the existing platform warehouse-management
-- capability.  Every callable V3 route must still have its own registration:
-- the platform middleware intentionally rejects unregistered inventory routes.
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓批次入库','','','','product/inventory/v3/hq/inbound','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/inbound' AND `methods`='POST' AND `is_del`=0);
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓跨主体调拨查询','','','','product/inventory/v3/hq/cross-transfer','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/cross-transfer' AND `methods`='GET' AND `is_del`=0);
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓跨主体调拨对象','','','','product/inventory/v3/hq/cross-transfer/counterparties','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/cross-transfer/counterparties' AND `methods`='GET' AND `is_del`=0);
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓待履约请货单','','','','product/inventory/v3/hq/cross-transfer/incoming-requests','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/cross-transfer/incoming-requests' AND `methods`='GET' AND `is_del`=0);
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓跨主体调拨详情','','','','product/inventory/v3/hq/cross-transfer/<id>/detail','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/cross-transfer/<id>/detail' AND `methods`='GET' AND `is_del`=0);
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓跨主体调拨草稿','','','','product/inventory/v3/hq/cross-transfer','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/cross-transfer' AND `methods`='POST' AND `is_del`=0);
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓跨主体调拨发货','','','','product/inventory/v3/hq/cross-transfer/<id>/dispatch','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/cross-transfer/<id>/dispatch' AND `methods`='POST' AND `is_del`=0);
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓跨主体调拨收货','','','','product/inventory/v3/hq/cross-transfer/<id>/receive','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/cross-transfer/<id>/receive' AND `methods`='POST' AND `is_del`=0);
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓跨主体调拨取消','','','','product/inventory/v3/hq/cross-transfer/<id>/cancel','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/cross-transfer/<id>/cancel' AND `methods`='POST' AND `is_del`=0);

SELECT 'APPLY_OK' AS apply_result;
