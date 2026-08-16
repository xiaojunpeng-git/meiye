SET NAMES utf8mb4;

SELECT `menu_name`,`menu_path`,`unique_auth`,`is_show`,`is_del`
FROM `eb_system_menus`
WHERE `unique_auth` LIKE 'admin-report-store-operations-%'
  AND `is_del`=0
ORDER BY `sort` DESC,`id` ASC;

SELECT COUNT(*) AS report_menu_count
FROM `eb_system_menus`
WHERE `type`=1 AND `is_del`=0 AND `is_show`=1
  AND `unique_auth` LIKE 'admin-report-store-operations-%';

SELECT `unique_auth`,`is_show`,`is_del`
FROM `eb_system_menus`
WHERE `unique_auth`='admin-report-store-operations';
