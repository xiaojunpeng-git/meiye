-- upgrade_key: 20260816-001-platform-store-operations-report-permissions
-- MySQL 5.6 compatible and idempotent.
SET NAMES utf8mb4;

-- 将已有的历史总入口收敛为“数据”下可见的一级菜单。不能只隐藏它：
-- 记录仍存在时后续 INSERT 会跳过，最终会出现子菜单无可见父级的问题。
UPDATE `eb_system_menus` parent_menu
JOIN `eb_system_menus` data_menu
  ON data_menu.`unique_auth`='admin-report' AND data_menu.`type`=1 AND data_menu.`is_del`=0
SET parent_menu.`pid`=data_menu.`id`,
    parent_menu.`icon`='ios-pie-outline',
    parent_menu.`menu_name`='门店运营',
    parent_menu.`menu_path`='/report/business-center',
    parent_menu.`path`=CAST(data_menu.`id` AS CHAR),
    parent_menu.`is_show`=1,
    parent_menu.`is_show_path`=0,
    parent_menu.`access`=1,
    parent_menu.`is_header`=0
WHERE parent_menu.`type`=1
  AND parent_menu.`unique_auth`='admin-report-store-operations'
  AND parent_menu.`is_del`=0;

-- 菜单 ID 会随实例不同而变化，按权限标识定位“数据”，不能写死历史 ID 1573。
INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT data_menu.id,1,'ios-pie-outline','门店运营','admin','','','','[]','[]',5,1,0,1,
  '/report/business-center',CAST(data_menu.id AS CHAR),1,'',0,'admin-report-store-operations',0
FROM `eb_system_menus` data_menu
WHERE data_menu.`unique_auth`='admin-report' AND data_menu.`type`=1 AND data_menu.`is_del`=0
  AND NOT EXISTS (
    SELECT 1 FROM `eb_system_menus` m
    WHERE m.`unique_auth`='admin-report-store-operations' AND m.`is_del`=0
  );

INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT parent_menu.id,1,'ios-stats-outline',r.menu_name,'admin','','','','[]','[]',19-r.sort_order,1,0,1,
  CONCAT('/report/store-operations/',r.report_code),CONCAT(data_menu.id,'/',parent_menu.id),1,'',0,
  CONCAT('admin-report-store-operations-',r.report_code),0
FROM (
  SELECT 1 AS sort_order,'partner_item_summary' AS report_code,'合作方品项汇总' AS menu_name UNION ALL
  SELECT 2,'partner_item_detail','合作方品项明细' UNION ALL
  SELECT 3,'member_consumption_detail','会员消费明细' UNION ALL
  SELECT 4,'store_item_analysis','门店品项分析' UNION ALL
  SELECT 5,'store_craftsman_consumption','门店手艺人消耗' UNION ALL
  SELECT 6,'store_salesperson_performance','门店销售人业绩' UNION ALL
  SELECT 7,'market_performance','市场业绩表' UNION ALL
  SELECT 8,'market_detail','市场明细表' UNION ALL
  SELECT 9,'member_visit_analysis','会员进店分析表' UNION ALL
  SELECT 10,'member_visit_annual_summary','会员进店年度汇总表' UNION ALL
  SELECT 11,'field_acquisition_detail','地推拓客明细表' UNION ALL
  SELECT 12,'field_acquisition_summary','地推拓客汇总表' UNION ALL
  SELECT 13,'cross_industry_customer_detail','异业收客明细表' UNION ALL
  SELECT 14,'cross_industry_customer_summary','异业收客汇总表' UNION ALL
  SELECT 15,'new_customer_analysis','新客明细表' UNION ALL
  SELECT 16,'new_customer_analysis_summary','新客汇总表' UNION ALL
  SELECT 17,'salesperson_large_order_statistics','销售人生美大单统计表' UNION ALL
  SELECT 18,'store_refund_ledger','院店退款台账'
) r
JOIN `eb_system_menus` parent_menu
  ON parent_menu.`unique_auth`='admin-report-store-operations' AND parent_menu.`type`=1 AND parent_menu.`is_del`=0
JOIN `eb_system_menus` data_menu
  ON data_menu.`id`=parent_menu.`pid` AND data_menu.`unique_auth`='admin-report' AND data_menu.`is_del`=0
WHERE EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `unique_auth`='admin-report' AND `is_del`=0)
  AND NOT EXISTS (
    SELECT 1 FROM `eb_system_menus` m
    WHERE m.`unique_auth`=CONCAT('admin-report-store-operations-',r.report_code)
      AND m.`is_del`=0
  );

INSERT INTO `eb_database_upgrade_log` (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260816-001-platform-store-operations-report-permissions','平台门店运营报表独立权限','2026-08-16-平台门店运营报表独立权限/02-正式升级.sql','', '',NOW(),'codex-local','18 张报表菜单已独立授权'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20260816-001-platform-store-operations-report-permissions'
);
