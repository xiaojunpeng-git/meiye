-- upgrade_key: 20260804-002-inventory-v3-platform-home-menu
SET NAMES utf8mb4;

SELECT `id`,`pid`,`menu_name`,`menu_path`,`unique_auth`,`is_show`,`is_del`
FROM `eb_system_menus`
WHERE `type`=1 AND `is_del`=0 AND `menu_path`='/admin/stock/manage/home';

