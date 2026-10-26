-- upgrade_key: 20260814-005-inventory-v3-platform-dashboard-route-auth
-- MySQL 5.6 compatible and re-runnable.
SET NAMES utf8mb4;

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台库存首页概览','','','','product/inventory/v3/dashboard','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-batch-view',0
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus`
  WHERE `type`=1
    AND `is_del`=0
    AND `api_url`='product/inventory/v3/dashboard'
    AND `methods`='GET'
);

SELECT 'APPLY_OK' AS apply_result;
