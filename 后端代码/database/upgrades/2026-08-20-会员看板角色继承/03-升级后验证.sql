-- upgrade_key: 20260820-001-member-dashboard-role-inheritance
SET NAMES utf8mb4;

SELECT COUNT(*) AS `group_roles_missing_member_dashboard`
FROM `eb_system_role`
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

SELECT `upgrade_key`, `executed_at`, `executed_by`, `result_note`
FROM `eb_database_upgrade_log`
WHERE `upgrade_key`='20260820-001-member-dashboard-role-inheritance';
