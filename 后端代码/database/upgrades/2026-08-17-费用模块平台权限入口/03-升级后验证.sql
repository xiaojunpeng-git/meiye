-- upgrade_key: 20260817-001-fund-platform-menu-permission
SET NAMES utf8mb4;

SELECT parent_menu.`menu_name` AS parent_name,
       fund_menu.`id`,fund_menu.`menu_name`,fund_menu.`menu_path`,fund_menu.`unique_auth`,
       fund_menu.`is_show`,fund_menu.`is_del`
FROM `eb_system_menus` fund_menu
JOIN `eb_system_menus` parent_menu ON parent_menu.`id`=fund_menu.`pid`
WHERE fund_menu.`type`=1
  AND fund_menu.`is_del`=0
  AND fund_menu.`unique_auth`='admin-fund-manage';

SELECT `upgrade_key`,`executed_at`,`result_note`
FROM `eb_database_upgrade_log`
WHERE `upgrade_key`='20260817-001-fund-platform-menu-permission';
