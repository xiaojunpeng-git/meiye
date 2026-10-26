-- upgrade_key: 20260805-001-inventory-v3-menu-consolidation-hq-outbound
SET NAMES utf8mb4;

-- The summary has been absorbed by the inventory query/statistics views.
-- Keep old addresses routable, but remove the duplicate menu entry for both ends.
UPDATE `eb_system_menus`
SET `is_show`=0
WHERE `type`=1 AND `is_del`=0
  AND `unique_auth` IN ('admin-inventory-statistics','store-inventory-statistics');

-- Platform middleware rejects unregistered V3 endpoints before the controller.
-- Commands retain the warehouse-manage capability; read projections retain the
-- platform inventory read capability and always resolve the HQ location server-side.
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓手工出库','','','','product/inventory/v3/hq/outbound','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0
    AND `api_url`='product/inventory/v3/hq/outbound' AND `methods`='POST'
);

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓出库查询','','','','product/inventory/v3/hq/outbound','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0
    AND `api_url`='product/inventory/v3/hq/outbound' AND `methods`='GET'
);

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓商品汇总','','','','product/inventory/v3/hq/product-summary','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0
    AND `api_url`='product/inventory/v3/hq/product-summary' AND `methods`='GET'
);

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓商品详情','','','','product/inventory/v3/hq/product-summary/:productId/detail','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0
    AND `api_url`='product/inventory/v3/hq/product-summary/:productId/detail' AND `methods`='GET'
);
