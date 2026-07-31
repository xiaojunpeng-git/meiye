-- upgrade_key: 20260731-005-inventory-v3-store-cost-permission
SET NAMES utf8mb4;

SELECT COUNT(*) AS active_cost_permission_count
FROM eb_system_menus
WHERE type=2 AND is_del=0 AND unique_auth='inventory.cost.view';

SELECT COUNT(*) AS roles_granted_cost_permission
FROM eb_system_role
WHERE status=1 AND FIND_IN_SET(
  (SELECT id FROM eb_system_menus WHERE type=2 AND is_del=0 AND unique_auth='inventory.cost.view' LIMIT 1),
  rules
);
