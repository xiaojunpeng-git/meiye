-- 预期恰有一个可见的平台仓库设置导航节点；其他同权限 API 节点不应变成菜单。
SELECT COUNT(*) AS visible_warehouse_settings_menus
FROM `eb_system_menus`
WHERE `type` = 1
  AND `auth_type` = 1
  AND `is_del` = 0
  AND `is_show` = 1
  AND `unique_auth` = 'inventory-v3-platform-warehouse-settings'
  AND `menu_path` = '/admin/stock/warehouse';
