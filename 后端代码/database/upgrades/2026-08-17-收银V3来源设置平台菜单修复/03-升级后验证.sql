-- upgrade_key: 20260817-002-cashier-v3-business-config-platform-menu-repair
SET NAMES utf8mb4;

SELECT page_menu.id,page_menu.menu_name,page_menu.menu_path,page_menu.path,page_menu.unique_auth,
       parent_menu.id AS parent_id,parent_menu.menu_name AS parent_name,
       page_menu.is_show,page_menu.is_del
FROM eb_system_menus page_menu
JOIN eb_system_menus parent_menu ON parent_menu.id=page_menu.pid
WHERE page_menu.type=1 AND page_menu.is_del=0
  AND page_menu.unique_auth IN ('setting-shop-business-source','setting-shop-accounting')
ORDER BY page_menu.id;

SELECT COUNT(*) AS roles_missing_page_menu_count
FROM eb_system_role role_row
WHERE role_row.status=1
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE type=1 AND is_del=0 AND unique_auth='admin-setting-shop' ORDER BY id LIMIT 1), role_row.rules)>0
  AND (
    FIND_IN_SET((SELECT id FROM eb_system_menus WHERE type=1 AND is_del=0 AND unique_auth='setting-shop-business-source' ORDER BY id LIMIT 1), role_row.rules)=0
    OR FIND_IN_SET((SELECT id FROM eb_system_menus WHERE type=1 AND is_del=0 AND unique_auth='setting-shop-accounting' ORDER BY id LIMIT 1), role_row.rules)=0
  );

SELECT COUNT(*) AS roles_missing_business_config_api_count
FROM eb_system_role role_row
WHERE role_row.status=1
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE type=1 AND is_del=0 AND unique_auth='admin-setting-shop' ORDER BY id LIMIT 1), role_row.rules)>0
  AND FIND_IN_SET((SELECT MIN(id) FROM eb_system_menus WHERE type=1 AND is_del=0 AND unique_auth='cashier-v3-business-config-manage'), role_row.rules)=0;

SELECT upgrade_key,executed_at,result_note
FROM eb_database_upgrade_log
WHERE upgrade_key='20260817-002-cashier-v3-business-config-platform-menu-repair';
