-- upgrade_key: 20260817-005-business-source-menu-last
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT parent_menu.id,parent_menu.menu_name,parent_menu.unique_auth
FROM eb_system_menus parent_menu
WHERE parent_menu.type=1
  AND parent_menu.is_del=0
  AND parent_menu.unique_auth='admin-setting-shop'
ORDER BY parent_menu.id ASC;

SELECT child_menu.id,child_menu.menu_name,child_menu.sort,child_menu.is_show,child_menu.unique_auth
FROM eb_system_menus child_menu
WHERE child_menu.type=1
  AND child_menu.is_del=0
  AND child_menu.pid=(
    SELECT id FROM eb_system_menus
    WHERE type=1 AND is_del=0 AND unique_auth='admin-setting-shop'
    ORDER BY id ASC LIMIT 1
  )
ORDER BY child_menu.sort ASC,child_menu.id ASC;
