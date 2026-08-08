-- upgrade_key: 20260804-002-inventory-v3-platform-home-menu
SET NAMES utf8mb4;

SELECT COUNT(*) AS inventory_parent_count
FROM `eb_system_menus`
WHERE `type`=1 AND `is_del`=0 AND `unique_auth`='admin-stock-manage';

SELECT COUNT(*) AS inventory_home_count
FROM `eb_system_menus`
WHERE `type`=1 AND `is_del`=0 AND `menu_path`='/admin/stock/manage/home';

