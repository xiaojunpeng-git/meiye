-- upgrade_key: 20260814-005-inventory-v3-platform-dashboard-route-auth
SET NAMES utf8mb4;

SELECT `id`,`menu_name`,`api_url`,`methods`,`unique_auth`,`is_del`
FROM `eb_system_menus`
WHERE `type`=1
  AND `is_del`=0
  AND `api_url`='product/inventory/v3/dashboard'
  AND `methods`='GET'
  AND `unique_auth`='inventory-v3-platform-batch-view';
