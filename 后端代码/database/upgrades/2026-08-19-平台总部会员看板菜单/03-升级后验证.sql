-- upgrade_key: 20260819-001-platform-hq-member-dashboard-menu
SET NAMES utf8mb4;

SELECT child.`id`,child.`pid`,parent.`menu_name` AS `parent_menu_name`,
       child.`menu_name`,child.`menu_path`,child.`unique_auth`,
       child.`is_show`,child.`is_del`,child.`sort`
FROM `eb_system_menus` child
JOIN `eb_system_menus` parent ON parent.`id`=child.`pid`
WHERE child.`unique_auth`='admin-report-member-management-dashboard'
  AND child.`is_del`=0;

SELECT COUNT(*) AS `valid_member_dashboard_menu_count`
FROM `eb_system_menus` child
JOIN `eb_system_menus` parent ON parent.`id`=child.`pid`
WHERE child.`unique_auth`='admin-report-member-management-dashboard'
  AND child.`menu_name`='会员看板'
  AND child.`menu_path`='/report/member-management-dashboard'
  AND child.`is_show`=1
  AND child.`is_del`=0
  AND parent.`unique_auth`='admin-index-index'
  AND parent.`menu_name`='总部'
  AND parent.`type`=1
  AND parent.`is_del`=0;

SELECT `upgrade_key`,`executed_at`,`executed_by`,`result_note`
FROM `eb_database_upgrade_log`
WHERE `upgrade_key`='20260819-001-platform-hq-member-dashboard-menu';
