-- upgrade_key: 20260803-004-inventory-v3-platform-hq-catalog
SET NAMES utf8mb4;

INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部库存商品目录','','','','product/inventory/v3/hq/catalog','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus`
  WHERE `type`=1 AND `is_del`=0
    AND `api_url`='product/inventory/v3/hq/catalog' AND `methods`='GET'
);

INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台总部仓入库查询','','','','product/inventory/v3/hq/inbound','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus`
  WHERE `type`=1 AND `is_del`=0
    AND `api_url`='product/inventory/v3/hq/inbound' AND `methods`='GET'
);

-- The release workflow records this upgrade only after 01 and 03 both pass.
-- That record includes the actual SQL checksum, fixed source commit and executor.
