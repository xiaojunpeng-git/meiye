-- upgrade_key: 20260909-001-platform-store-order-center-menu
-- Read-only. Do not run 02 if active_parent_count is not 1.
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT id,pid,type,menu_name,menu_path,unique_auth,is_show,is_del,sort,path
FROM eb_system_menus
WHERE unique_auth IN (
  'admin-store-order',
  'admin-store-store_order',
  'admin-store-refund-order',
  'admin-store-writeoff',
  'admin-store-order-center-sales',
  'admin-store-order-center-recharge',
  'admin-store-order-center-refund',
  'admin-store-order-center-debt',
  'admin-store-order-center-service',
  'admin-store-order-center-supplement',
  'admin-store-order-center-gift',
  'admin-store-order-center-card-operation'
)
ORDER BY pid,sort,id;

SELECT COUNT(*) AS active_parent_count
FROM eb_system_menus
WHERE unique_auth='admin-store-order'
  AND menu_name='门店订单'
  AND type=1 AND is_del=0;

SELECT
  COUNT(DISTINCT CASE WHEN unique_auth IN (
    'admin-store-order-center-sales',
    'admin-store-order-center-recharge',
    'admin-store-order-center-refund',
    'admin-store-order-center-debt',
    'admin-store-order-center-service',
    'admin-store-order-center-supplement',
    'admin-store-order-center-gift',
    'admin-store-order-center-card-operation'
  ) AND is_del=0 THEN unique_auth END) AS active_new_menu_count,
  SUM(unique_auth IN ('admin-store-store_order','admin-store-refund-order','admin-store-writeoff')
      AND is_del=0 AND is_show=1) AS visible_legacy_child_count
FROM eb_system_menus;

SELECT COUNT(*) AS parent_roles_before
FROM eb_system_role r
WHERE FIND_IN_SET((
  SELECT id FROM eb_system_menus
  WHERE unique_auth='admin-store-order' AND menu_name='门店订单' AND type=1 AND is_del=0
  LIMIT 1
), r.rules)>0;

SELECT upgrade_key,executed_at,executed_by,result_note
FROM eb_database_upgrade_log
WHERE upgrade_key='20260909-001-platform-store-order-center-menu';
