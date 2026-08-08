-- upgrade_key: 20260806-001-inventory-v3-requester-and-hq-count
SET NAMES utf8mb4;

SET @has_requester_name := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document'
    AND column_name='requester_name_snapshot'
);
SET @sql := IF(@has_requester_name=0,
  'ALTER TABLE `eb_inventory_stock_request_document` ADD COLUMN `requester_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `operator_id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_operator_name := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document'
    AND column_name='operator_name_snapshot'
);
SET @sql := IF(@has_operator_name=0,
  'ALTER TABLE `eb_inventory_stock_request_document` ADD COLUMN `operator_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `requester_name_snapshot`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓库存盘点','','','','product/inventory/v3/hq/count/confirm','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus`
  WHERE `type`=1 AND `is_del`=0
    AND `api_url`='product/inventory/v3/hq/count/confirm' AND `methods`='POST'
);

INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','库存商品精确条码查询','','','','product/inventory/v3/catalog/barcode','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-store-inbound-write',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `api_url`='product/inventory/v3/catalog/barcode' AND `methods`='GET');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','库存请货人默认信息','','','','product/inventory/v3/request/requester','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-store-request-write',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `api_url`='product/inventory/v3/request/requester' AND `methods`='GET');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部库存精确条码查询','','','','product/inventory/v3/hq/catalog/barcode','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `api_url`='product/inventory/v3/hq/catalog/barcode' AND `methods`='GET');
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部请货人默认信息','','','','product/inventory/v3/hq/request/requester','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `api_url`='product/inventory/v3/hq/request/requester' AND `methods`='GET');
