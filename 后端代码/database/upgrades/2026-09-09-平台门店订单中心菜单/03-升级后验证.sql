-- upgrade_key: 20260909-001-platform-store-order-center-menu
SET NAMES utf8mb4;

-- Before this SQL is treated as passed, clear the application Redis tag
-- `system_menus` and refresh the administrator's menu/permission snapshot.
-- This is an application-cache operation, not a SQL DELETE.

SELECT c.id,c.pid,p.menu_name AS parent_menu_name,c.menu_name,c.menu_path,c.unique_auth,c.is_show,c.is_del,c.sort
FROM eb_system_menus c
JOIN eb_system_menus p ON p.id=c.pid
WHERE c.unique_auth IN (
  'admin-store-order-center-sales',
  'admin-store-order-center-recharge',
  'admin-store-order-center-refund',
  'admin-store-order-center-debt',
  'admin-store-order-center-service',
  'admin-store-order-center-supplement',
  'admin-store-order-center-gift',
  'admin-store-order-center-card-operation'
)
ORDER BY c.sort,c.id;

SELECT COUNT(*) AS valid_new_menu_count
FROM eb_system_menus c
JOIN eb_system_menus p ON p.id=c.pid
WHERE p.unique_auth='admin-store-order' AND p.menu_name='门店订单' AND p.type=1 AND p.is_del=0
  AND c.unique_auth IN (
    'admin-store-order-center-sales',
    'admin-store-order-center-recharge',
    'admin-store-order-center-refund',
    'admin-store-order-center-debt',
    'admin-store-order-center-service',
    'admin-store-order-center-supplement',
    'admin-store-order-center-gift',
    'admin-store-order-center-card-operation'
  )
  AND c.is_show=1 AND c.is_del=0;

SELECT unique_auth,menu_name,menu_path,is_show,is_del
FROM eb_system_menus
WHERE unique_auth IN ('admin-store-store_order','admin-store-refund-order','admin-store-writeoff')
ORDER BY id;

SELECT COUNT(*) AS parent_roles_missing_any_new_tab
FROM eb_system_role r
WHERE FIND_IN_SET((
  SELECT id FROM eb_system_menus
  WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0
  LIMIT 1
),r.rules)>0
  AND (
    FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-sales' AND is_del=0 LIMIT 1),r.rules)=0
    OR FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-recharge' AND is_del=0 LIMIT 1),r.rules)=0
    OR FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-refund' AND is_del=0 LIMIT 1),r.rules)=0
    OR FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-debt' AND is_del=0 LIMIT 1),r.rules)=0
    OR FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-service' AND is_del=0 LIMIT 1),r.rules)=0
    OR FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-supplement' AND is_del=0 LIMIT 1),r.rules)=0
    OR FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-gift' AND is_del=0 LIMIT 1),r.rules)=0
    OR FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-store-order-center-card-operation' AND is_del=0 LIMIT 1),r.rules)=0
  );

SELECT upgrade_key,executed_at,executed_by,result_note
FROM eb_database_upgrade_log
WHERE upgrade_key='20260909-001-platform-store-order-center-menu';
