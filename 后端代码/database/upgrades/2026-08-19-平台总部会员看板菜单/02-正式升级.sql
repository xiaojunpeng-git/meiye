-- upgrade_key: 20260819-001-platform-hq-member-dashboard-menu
-- MySQL 5.6 compatible and idempotent. No existing menu is updated.
SET NAMES utf8mb4;

INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT `headquarters`.`id`,1,'ios-people-outline','会员看板','admin','','','','[]','[]',126,1,0,1,
       '/report/member-management-dashboard',CAST(`headquarters`.`id` AS CHAR),1,'',0,
       'admin-report-member-management-dashboard',0
FROM `eb_system_menus` `headquarters`
WHERE `headquarters`.`unique_auth`='admin-index-index'
  AND `headquarters`.`menu_name`='总部'
  AND `headquarters`.`type`=1
  AND `headquarters`.`is_del`=0
  AND NOT EXISTS (
    SELECT 1 FROM `eb_system_menus` `existing`
    WHERE `existing`.`unique_auth`='admin-report-member-management-dashboard'
      AND `existing`.`is_del`=0
  )
  AND (
    SELECT COUNT(*) FROM `eb_system_menus` `parent_check`
    WHERE `parent_check`.`unique_auth`='admin-index-index'
      AND `parent_check`.`menu_name`='总部'
      AND `parent_check`.`type`=1
      AND `parent_check`.`is_del`=0
  )=1;

INSERT INTO `eb_database_upgrade_log`
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260819-001-platform-hq-member-dashboard-menu',
       '平台总部会员看板菜单',
       '2026-08-19-平台总部会员看板菜单/02-正式升级.sql',
       '', '', NOW(), 'codex-local',
       'registered member dashboard under the existing headquarters menu'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20260819-001-platform-hq-member-dashboard-menu'
);
