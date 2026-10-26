-- 修复：目标管理显示在总后台顶部菜单
-- 顶部菜单条件：pid=0 且 is_header=1（见 admin/src/libs/system/index.js getHeaderSider）

UPDATE `eb_system_menus`
SET `is_header` = 1
WHERE `unique_auth` = 'admin-target'
  AND `pid` = 0
  AND `is_del` = 0;

-- 若上面未命中，可按菜单名更新（请确认无重名）
-- UPDATE `eb_system_menus` SET `is_header` = 1 WHERE `menu_name` = '目标管理' AND `pid` = 0 AND `type` = 1;

-- 执行后：设置 -> 系统维护 -> 刷新缓存，并重新登录后台
