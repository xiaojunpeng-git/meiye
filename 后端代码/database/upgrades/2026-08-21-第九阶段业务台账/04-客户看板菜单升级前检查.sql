SET NAMES utf8mb4;

SELECT id, pid, menu_name, menu_path, unique_auth, header, is_header, is_show
FROM eb_system_menus
WHERE unique_auth IN ('admin-crm', 'admin-customer-analytics-dashboard')
   OR unique_auth LIKE 'admin-customer-analytics-%'
ORDER BY pid, sort, id;

SELECT COUNT(*) AS active_customer_roles
FROM eb_system_role
WHERE is_del = 0
  AND FIND_IN_SET(
    (SELECT id FROM eb_system_menus WHERE unique_auth='admin-crm' AND is_del=0 LIMIT 1),
    rules
  );
