-- 排班菜单权限修复（单独执行此文件即可）
-- 执行后退出收银台重新登录

-- 1. 确保排班菜单存在
INSERT INTO `eb_system_menus`
(`id`, `pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT 1985, 0, 3, 'iconshouyintai-jiaojieban', '排班', 'admin', '', '', '', '', '[]', 124, 1, 0, 1, '/cashier/schedule/index', '', 1, '', 0, 'cashier-schedule-index', 0
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `unique_auth` = 'cashier-schedule-index' AND `is_del` = 0 LIMIT 1
);

UPDATE `eb_system_menus`
SET `sort` = 124,
    `icon` = 'iconshouyintai-jiaojieban',
    `menu_path` = '/cashier/schedule/index',
    `menu_name` = '排班',
    `is_show` = 1,
    `auth_type` = 1,
    `is_show_path` = 0
WHERE `unique_auth` = 'cashier-schedule-index' AND `is_del` = 0;

-- 2. 查看排班菜单实际 ID（执行后看结果，不一定是 1985）
SELECT `id`, `menu_name`, `menu_path`, `unique_auth`, `sort`
FROM `eb_system_menus`
WHERE `unique_auth` = 'cashier-schedule-index' AND `is_del` = 0;

-- 3. 给所有「已有收银台权限」的门店角色追加排班菜单（不依赖 1831/1410 等固定 ID）
UPDATE `eb_system_role` r
INNER JOIN (
  SELECT `id` FROM `eb_system_menus` WHERE `unique_auth` = 'cashier-schedule-index' AND `is_del` = 0 LIMIT 1
) m
SET r.`cashier_rules` = CASE
  WHEN r.`cashier_rules` IS NULL OR TRIM(r.`cashier_rules`) = '' THEN CAST(m.`id` AS CHAR)
  WHEN FIND_IN_SET(m.`id`, REPLACE(r.`cashier_rules`, ' ', '')) = 0 THEN CONCAT(TRIM(BOTH ',' FROM r.`cashier_rules`), ',', m.`id`)
  ELSE r.`cashier_rules`
END
WHERE r.`status` = 1
  AND r.`cashier_rules` IS NOT NULL
  AND TRIM(r.`cashier_rules`) <> '';

-- 4. 验证：应能查到至少一条记录（把下面的菜单 ID 换成第 2 步查到的 id）
-- SELECT id, role_name, cashier_rules FROM eb_system_role WHERE FIND_IN_SET('菜单ID', cashier_rules) > 0;
