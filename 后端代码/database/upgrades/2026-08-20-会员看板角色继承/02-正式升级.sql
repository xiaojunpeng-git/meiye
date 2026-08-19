-- upgrade_key: 20260820-001-member-dashboard-role-inheritance
-- MySQL 5.6 compatible and idempotent.
SET NAMES utf8mb4;

UPDATE `eb_system_role`
SET `rules` = CONCAT_WS(',', NULLIF(TRIM(BOTH ',' FROM `rules`), ''), (
  SELECT `id` FROM `eb_system_menus`
  WHERE `unique_auth`='admin-report-member-management-dashboard' AND `is_del`=0
  LIMIT 1
))
WHERE FIND_IN_SET((
  SELECT `id` FROM `eb_system_menus`
  WHERE `unique_auth`='admin-report-group-management-dashboard' AND `is_del`=0
  LIMIT 1
), `rules`) > 0
  AND FIND_IN_SET((
    SELECT `id` FROM `eb_system_menus`
    WHERE `unique_auth`='admin-report-member-management-dashboard' AND `is_del`=0
    LIMIT 1
  ), `rules`) = 0;

INSERT INTO `eb_database_upgrade_log`
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260820-001-member-dashboard-role-inheritance',
       '会员看板角色继承',
       '2026-08-20-会员看板角色继承/02-正式升级.sql',
       '', '', NOW(), 'codex-local',
       'granted member dashboard only to roles that already have group dashboard access'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20260820-001-member-dashboard-role-inheritance'
);
