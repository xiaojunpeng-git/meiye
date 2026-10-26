-- upgrade_key: 20260808-002-inventory-v3-request-party-requester-selector
SET NAMES utf8mb4;

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台请货方选择','','','','product/inventory/v3/hq/request/parties','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `api_url`='product/inventory/v3/hq/request/parties' AND `methods`='GET');
