-- upgrade_key: 20260817-001-fund-platform-menu-permission
SET NAMES utf8mb4;

SELECT `id`,`pid`,`menu_name`,`menu_path`,`path`,`unique_auth`,`is_show`,`is_del`
FROM `eb_system_menus`
WHERE `type`=1
  AND `unique_auth` IN ('admin-store-finance','admin-fund-manage')
ORDER BY `id` ASC;
