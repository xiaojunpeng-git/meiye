SET NAMES utf8mb4;

SELECT parent.menu_name AS parent_menu, child.menu_name, child.menu_path, child.unique_auth
FROM eb_system_menus child
JOIN eb_system_menus parent ON parent.id=child.pid
WHERE parent.unique_auth='admin-customer-analytics-dashboard'
  AND child.is_del=0
ORDER BY child.sort, child.id;

SELECT COUNT(*) AS customer_dashboard_children
FROM eb_system_menus
WHERE pid=(SELECT id FROM eb_system_menus WHERE unique_auth='admin-customer-analytics-dashboard' AND is_del=0 LIMIT 1)
  AND unique_auth LIKE 'admin-customer-analytics-customer_%'
  AND is_del=0;
