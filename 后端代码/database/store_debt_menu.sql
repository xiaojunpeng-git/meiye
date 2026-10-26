-- 门店端「欠款管理」菜单（订单模块下）
-- 执行后门店账号需重新登录；并在「门店角色」中勾选该菜单权限

INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT 1052, 2, '', '欠款管理', 'admin', '', '', '', '', '[]', 0, 1, 0, 1, '/store/order/debt/index', '1052', 1, '', 0, 'store-order-debt', 0
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_system_menus` WHERE `unique_auth` = 'store-order-debt' AND `is_del` = 0 LIMIT 1
);

UPDATE `eb_system_menus`
SET `pid` = 1052,
    `type` = 2,
    `menu_name` = '欠款管理',
    `menu_path` = '/store/order/debt/index',
    `path` = '1052',
    `auth_type` = 1,
    `is_show` = 1,
    `is_show_path` = 0,
    `unique_auth` = 'store-order-debt'
WHERE `unique_auth` = 'store-order-debt' AND `is_del` = 0;

-- 查看菜单 ID（用于角色授权）
SELECT `id`, `menu_name`, `menu_path`, `unique_auth`
FROM `eb_system_menus`
WHERE `unique_auth` = 'store-order-debt' AND `is_del` = 0;

-- 给已有「订单列表」权限的门店角色追加欠款管理（按需执行）
UPDATE `eb_system_role` r
INNER JOIN (
  SELECT `id` FROM `eb_system_menus` WHERE `unique_auth` = 'store-order-debt' AND `is_del` = 0 LIMIT 1
) debtMenu
INNER JOIN (
  SELECT `id` FROM `eb_system_menus` WHERE `unique_auth` = 'store-order-index' AND `is_del` = 0 LIMIT 1
) orderMenu
SET r.`rules` = CASE
  WHEN r.`rules` IS NULL OR TRIM(r.`rules`) = '' THEN CAST(debtMenu.`id` AS CHAR)
  WHEN FIND_IN_SET(debtMenu.`id`, REPLACE(r.`rules`, ' ', '')) = 0 THEN CONCAT(TRIM(BOTH ',' FROM r.`rules`), ',', debtMenu.`id`)
  ELSE r.`rules`
END
WHERE r.`status` = 1
  AND FIND_IN_SET(orderMenu.`id`, REPLACE(r.`rules`, ' ', '')) > 0;
