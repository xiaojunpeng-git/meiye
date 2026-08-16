-- upgrade_key: 20260817-001-fund-platform-menu-permission
-- 平台费用入口固定挂在“财务 / 门店财务”末级菜单，由角色权限规则控制可见性。
-- 不使用历史菜单 ID，确保各客户独立实例可重复执行。
SET NAMES utf8mb4;

SET @fund_parent_id := (
  SELECT `id`
  FROM `eb_system_menus`
  WHERE `type`=1 AND `is_del`=0 AND `unique_auth`='admin-store-finance'
  ORDER BY `id` ASC
  LIMIT 1
);

INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT parent_menu.`id`,1,'ios-cash-outline','费用','admin','','','','','[]',0,1,0,1,
  '/admin/finance/store_fund',CONCAT(parent_menu.`path`,'/',parent_menu.`id`),1,'',0,'admin-fund-manage',0
FROM `eb_system_menus` parent_menu
WHERE parent_menu.`id`=@fund_parent_id
  AND NOT EXISTS (
    SELECT 1 FROM `eb_system_menus` existing_menu
    WHERE existing_menu.`type`=1 AND existing_menu.`is_del`=0 AND existing_menu.`unique_auth`='admin-fund-manage'
  );

UPDATE `eb_system_menus` fund_menu
JOIN `eb_system_menus` parent_menu ON parent_menu.`id`=@fund_parent_id
SET fund_menu.`pid`=parent_menu.`id`,
    fund_menu.`icon`='ios-cash-outline',
    fund_menu.`menu_name`='费用',
    fund_menu.`module`='admin',
    fund_menu.`controller`='',
    fund_menu.`action`='',
    fund_menu.`api_url`='',
    fund_menu.`methods`='',
    fund_menu.`params`='[]',
    fund_menu.`sort`=0,
    fund_menu.`is_show`=1,
    fund_menu.`is_show_path`=0,
    fund_menu.`access`=1,
    fund_menu.`menu_path`='/admin/finance/store_fund',
    fund_menu.`path`=CONCAT(parent_menu.`path`,'/',parent_menu.`id`),
    fund_menu.`auth_type`=1,
    fund_menu.`header`='',
    fund_menu.`is_header`=0,
    fund_menu.`is_del`=0
WHERE fund_menu.`type`=1 AND fund_menu.`unique_auth`='admin-fund-manage';

INSERT INTO `eb_database_upgrade_log` (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260817-001-fund-platform-menu-permission','费用模块平台权限入口','2026-08-17-费用模块平台权限入口/02-正式升级.sql','', '',NOW(),'codex','已配置财务/门店财务/费用独立菜单权限'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20260817-001-fund-platform-menu-permission'
);
