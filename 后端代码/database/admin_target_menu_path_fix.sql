-- 修复目标管理菜单路由（须与前端路由一致，使用下划线 data_analysis）
UPDATE `eb_system_menus`
SET `menu_path` = '/admin/target/data_analysis'
WHERE `unique_auth` = 'admin-target-data-analysis'
   OR `menu_name` = '目标数据分析';

UPDATE `eb_system_menus`
SET `menu_path` = '/admin/target'
WHERE `unique_auth` = 'admin-target' AND `pid` = 0;

-- 执行后：设置 -> 权限设置 -> 刷新缓存，并重新登录总后台
