-- upgrade_key: 20260814-001-admin-data-store-operations-menu
-- MySQL 5.6 compatible and idempotent.
SET NAMES utf8mb4;

INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT
  1573, 1, 'ios-pie-outline', '门店运营', 'admin', '', '', '', '[]', '[]', 5, 1, 0, 1,
  '/report/business-center', '1573', 1, '', 0, 'admin-report-store-operations', 0
FROM DUAL
WHERE EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `id` = 1573 AND `is_del` = 0)
  AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `unique_auth` = 'admin-report-store-operations' AND `is_del` = 0);

INSERT INTO `eb_database_upgrade_log` (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260814-001-admin-data-store-operations-menu', '平台数据菜单新增门店运营', '2026-08-14-平台数据门店运营菜单/02-正式升级.sql', '', '', NOW(), 'codex-local', 'menu inserted'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `eb_database_upgrade_log` WHERE `upgrade_key` = '20260814-001-admin-data-store-operations-menu');
