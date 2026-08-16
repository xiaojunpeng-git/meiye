-- upgrade_key: 20260817-002-cashier-v3-business-config-platform-menu-repair
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT id,pid,menu_name,menu_path,path,unique_auth,is_show,is_del
FROM eb_system_menus
WHERE type=1
  AND unique_auth IN (
    'admin-setting-shop',
    'setting-shop-business-source',
    'setting-shop-accounting',
    'cashier-v3-business-config-manage'
  )
ORDER BY id;

SELECT id,status,rules
FROM eb_system_role
WHERE status=1
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE type=1 AND is_del=0 AND unique_auth='admin-setting-shop' ORDER BY id LIMIT 1), rules)>0
ORDER BY id;
