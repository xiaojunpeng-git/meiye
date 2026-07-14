-- 收银台「房间」菜单权限修复（单独执行此文件即可）
-- 执行后退出收银台重新登录

-- 1. 确保房间菜单存在
INSERT INTO `eb_system_menus`
(`id`, `pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT 1520, 0, 3, 'iconshouyintai-zhuoma', '房间', 'admin', '', '', '', '', '[]', 2, 1, 0, 1, '/cashier/table/index', '', 1, '', 0, 'cashier-table-index', 0
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `unique_auth` = 'cashier-table-index' AND `is_del` = 0 LIMIT 1
);

UPDATE `eb_system_menus`
SET `sort` = 2,
    `icon` = 'iconshouyintai-zhuoma',
    `menu_path` = '/cashier/table/index',
    `menu_name` = '房间',
    `is_show` = 1,
    `auth_type` = 1,
    `type` = 3,
    `is_show_path` = 0
WHERE `unique_auth` = 'cashier-table-index' AND `is_del` = 0;

-- 2. 查看房间菜单实际 ID（执行后看结果，不一定是 1520）
SELECT `id`, `menu_name`, `menu_path`, `unique_auth`, `sort`
FROM `eb_system_menus`
WHERE `unique_auth` = 'cashier-table-index' AND `is_del` = 0;

-- 3. 给所有「已有收银台权限」的门店角色追加房间菜单
UPDATE `eb_system_role` r
INNER JOIN (
  SELECT `id` FROM `eb_system_menus` WHERE `unique_auth` = 'cashier-table-index' AND `is_del` = 0 LIMIT 1
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
