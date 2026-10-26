-- upgrade_key: 20260820-001-member-dashboard-role-inheritance
SET NAMES utf8mb4;

SELECT `id`, `menu_name`, `unique_auth`, `is_del`
FROM `eb_system_menus`
WHERE `unique_auth` IN (
  'admin-report-group-management-dashboard',
  'admin-report-member-management-dashboard'
);

SELECT `id`, `role_name`
FROM `eb_system_role`
WHERE FIND_IN_SET((
  SELECT `id` FROM `eb_system_menus`
  WHERE `unique_auth`='admin-report-group-management-dashboard' AND `is_del`=0
  LIMIT 1
), `rules`) > 0;
