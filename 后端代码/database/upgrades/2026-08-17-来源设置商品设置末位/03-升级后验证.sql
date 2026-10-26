-- upgrade_key: 20260817-005-business-source-menu-last
SET NAMES utf8mb4;

SELECT source_menu.id,source_menu.menu_name,source_menu.sort,
       (
         SELECT MAX(sibling_menu.sort)
         FROM eb_system_menus sibling_menu
         WHERE sibling_menu.type=1
           AND sibling_menu.is_del=0
           AND sibling_menu.is_show=1
           AND sibling_menu.pid=source_menu.pid
       ) AS visible_sibling_max_sort,
       source_menu.menu_path,source_menu.unique_auth
FROM eb_system_menus source_menu
WHERE source_menu.type=1
  AND source_menu.is_del=0
  AND source_menu.unique_auth='setting-shop-business-source';

SELECT upgrade_key,executed_at,result_note
FROM eb_database_upgrade_log
WHERE upgrade_key='20260817-005-business-source-menu-last';
