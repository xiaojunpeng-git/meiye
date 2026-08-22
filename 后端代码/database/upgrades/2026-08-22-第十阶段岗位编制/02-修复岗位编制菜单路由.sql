-- upgrade_key: 20260822-002-staffing-menu-route
-- 岗位编制属于组织菜单，但必须挂在不要求 admin-report 的组织路由树下。
SET NAMES utf8mb4;

UPDATE `eb_system_menus`
SET `menu_path`='/admin/store/region/staffing'
WHERE `unique_auth`='admin-organization-staffing' AND `is_del`=0;

INSERT INTO `eb_database_upgrade_log`
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260822-002-staffing-menu-route','修复岗位编制组织菜单路由','2026-08-22-第十阶段岗位编制/02-修复岗位编制菜单路由.sql','','',NOW(),'codex-local','避免岗位编制被报表父权限拦截'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_database_upgrade_log` WHERE `upgrade_key`='20260822-002-staffing-menu-route');
