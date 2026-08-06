-- upgrade_key: 20260806-003-cashier-v3-business-config-platform-menu
SET NAMES utf8mb4;

SELECT id,pid,menu_name,menu_path,path,unique_auth,is_show,is_del
FROM eb_system_menus
WHERE is_del=0
  AND unique_auth IN ('setting-shop-business-source','setting-shop-accounting')
ORDER BY id;

SELECT COUNT(*) AS visible_page_menu_count
FROM eb_system_menus
WHERE is_del=0 AND is_show=1
  AND unique_auth IN ('setting-shop-business-source','setting-shop-accounting');

SELECT COUNT(*) AS roles_missing_page_menu_count
FROM eb_system_role role_row
WHERE role_row.status=1
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE is_del=0 AND unique_auth='admin-setting-shop' ORDER BY id LIMIT 1), role_row.rules)>0
  AND (
    FIND_IN_SET((SELECT id FROM eb_system_menus WHERE is_del=0 AND unique_auth='setting-shop-business-source' ORDER BY id LIMIT 1), role_row.rules)=0
    OR FIND_IN_SET((SELECT id FROM eb_system_menus WHERE is_del=0 AND unique_auth='setting-shop-accounting' ORDER BY id LIMIT 1), role_row.rules)=0
  );
