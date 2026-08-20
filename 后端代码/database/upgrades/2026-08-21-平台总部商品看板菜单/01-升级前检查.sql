-- upgrade_key: 20260821-001-platform-hq-product-dashboard-menu
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT id,pid,type,menu_name,menu_path,unique_auth,is_show,is_del,sort
FROM eb_system_menus
WHERE (unique_auth='admin-index-index' AND menu_name='总部')
   OR unique_auth IN ('admin-report-group-management-dashboard','admin-report-product-dashboard')
ORDER BY pid,sort,id;

SELECT
  (SELECT COUNT(*) FROM eb_system_menus
   WHERE unique_auth='admin-index-index' AND menu_name='总部'
     AND type=1 AND is_del=0) AS headquarters_parent_count,
  (SELECT COUNT(*) FROM eb_system_menus
   WHERE unique_auth='admin-report-product-dashboard' AND is_del=0) AS product_dashboard_menu_count,
  (SELECT COUNT(*) FROM eb_database_upgrade_log
   WHERE upgrade_key='20260821-001-platform-hq-product-dashboard-menu') AS upgrade_log_count;
