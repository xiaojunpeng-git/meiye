-- 收银台排班：班次模板 + 排班关联班次 + 左侧菜单权限
-- 执行后请重新登录收银台

CREATE TABLE IF NOT EXISTS `eb_store_staff_shift` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '0=全部门店可用',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '班次名称',
  `start_time` varchar(8) NOT NULL DEFAULT '' COMMENT '开始 HH:mm',
  `end_time` varchar(8) NOT NULL DEFAULT '' COMMENT '结束 HH:mm',
  `break_periods` text COMMENT '休息时段JSON',
  `sort` int(11) NOT NULL DEFAULT '0',
  `is_del` tinyint(1) NOT NULL DEFAULT '0',
  `add_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_store_del` (`store_id`,`is_del`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店员工班次模板';

-- 若列已存在可忽略报错
ALTER TABLE `eb_store_staff_schedule` ADD COLUMN `shift_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '班次模板ID' AFTER `staff_id`;

-- 收银台左侧菜单：排班（sort=124，比预约125小，显示在预约下方）
INSERT INTO `eb_system_menus`
(`id`, `pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT 1985, 0, 3, 'iconshouyintai-jiaojieban', '排班', 'admin', '', '', '', '', '[]', 124, 1, 0, 1, '/cashier/schedule/index', '', 1, '', 0, 'cashier-schedule-index', 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `unique_auth` = 'cashier-schedule-index' AND `is_del` = 0 LIMIT 1);

-- 若菜单已存在但 id 不是 1985，修正排序/图标/路径
UPDATE `eb_system_menus`
SET `sort` = 124,
    `icon` = 'iconshouyintai-jiaojieban',
    `menu_path` = '/cashier/schedule/index',
    `menu_name` = '排班',
    `is_show` = 1,
    `auth_type` = 1,
    `is_show_path` = 0
WHERE `unique_auth` = 'cashier-schedule-index' AND `is_del` = 0;

-- 给已有收银台权限的角色追加排班菜单（按实际菜单 ID，不依赖固定 ID）
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
