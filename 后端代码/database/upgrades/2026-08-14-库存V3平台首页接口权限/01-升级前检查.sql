-- upgrade_key: 20260814-005-inventory-v3-platform-dashboard-route-auth
SET NAMES utf8mb4;

SELECT COUNT(*) AS dashboard_route_permission_count
FROM `eb_system_menus`
WHERE `type`=1
  AND `is_del`=0
  AND `api_url`='product/inventory/v3/dashboard'
  AND `methods`='GET';

SELECT COUNT(*) AS inventory_view_capability_count
FROM `eb_system_menus`
WHERE `type`=1
  AND `is_del`=0
  AND `unique_auth`='inventory-v3-platform-batch-view';
