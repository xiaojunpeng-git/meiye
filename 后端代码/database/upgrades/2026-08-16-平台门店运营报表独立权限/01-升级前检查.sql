-- upgrade_key: 20260816-001-platform-store-operations-report-permissions
-- 每张门店运营报表必须是独立可分配的菜单，不允许前端生成。
SET NAMES utf8mb4;

SELECT `id`,`pid`,`menu_name`,`menu_path`,`unique_auth`,`is_show`,`is_del`
FROM `eb_system_menus`
WHERE `unique_auth`='admin-report-store-operations'
   OR `unique_auth` LIKE 'admin-report-store-operations-%'
ORDER BY `id`;

SELECT COUNT(*) AS data_menu_count
FROM `eb_system_menus`
WHERE `unique_auth`='admin-report' AND `type`=1 AND `is_del`=0;
