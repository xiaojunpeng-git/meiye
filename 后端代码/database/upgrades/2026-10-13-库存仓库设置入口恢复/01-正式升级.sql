-- upgrade_key: 20261013-003-inventory-warehouse-settings-menu
-- 仅恢复已有平台仓库设置菜单的可见性；岗位规则与仓库读写权限保持不变。
UPDATE `eb_system_menus`
SET `is_show` = 1
WHERE `type` = 1
  AND `auth_type` = 1
  AND `is_del` = 0
  AND `unique_auth` = 'inventory-v3-platform-warehouse-settings'
  AND `menu_path` = '/admin/stock/warehouse';
