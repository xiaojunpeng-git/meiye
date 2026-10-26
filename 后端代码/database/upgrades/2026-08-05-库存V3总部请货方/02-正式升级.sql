-- upgrade_key: 20260805-002-inventory-v3-hq-request-party
SET NAMES utf8mb4;

SET @has_request_party_type := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND column_name='request_party_type');
SET @sql := IF(@has_request_party_type=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD COLUMN `request_party_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''STORE'' AFTER `store_id`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_request_party_id := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND column_name='request_party_id');
SET @sql := IF(@has_request_party_id=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD COLUMN `request_party_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `request_party_type`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_request_party_name := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND column_name='request_party_name_snapshot');
SET @sql := IF(@has_request_party_name=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD COLUMN `request_party_name_snapshot` varchar(120) NOT NULL DEFAULT '''' AFTER `request_party_id`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_request_party_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND index_name='idx_request_party_status');
SET @sql := IF(@has_request_party_index=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD KEY `idx_request_party_status` (`tenant_id`,`request_party_type`,`request_party_id`,`document_status`,`id`)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部请货供货方目录','','','','product/inventory/v3/hq/request/suppliers','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `api_url`='product/inventory/v3/hq/request/suppliers' AND `methods`='GET');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部请货供货方选择','','','','product/inventory/v3/hq/request/counterparties','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `api_url`='product/inventory/v3/hq/request/counterparties' AND `methods`='GET');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部请货申请','','','','product/inventory/v3/hq/request/apply','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `api_url`='product/inventory/v3/hq/request/apply' AND `methods`='POST');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部请货查询','','','','product/inventory/v3/hq/request','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-batch-view',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `api_url`='product/inventory/v3/hq/request' AND `methods`='GET');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部请货详情','','','','product/inventory/v3/hq/request/:id/detail','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-batch-view',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `api_url`='product/inventory/v3/hq/request/:id/detail' AND `methods`='GET');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台库存统一业务查询','','','','product/inventory/v3/unified-query/operational','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-batch-view',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `api_url`='product/inventory/v3/unified-query/operational' AND `methods`='GET');
