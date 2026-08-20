-- upgrade_key: 20260821-001-platform-hq-product-dashboard-menu
-- MySQL 5.6 compatible and idempotent. Run the read-only check first.
SET NAMES utf8mb4;

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT h.id,1,'ios-cube-outline','商品看板','admin','','','','[]','[]',127,1,0,1,
       '/report/product-dashboard',CAST(h.id AS CHAR),1,'',0,
       'admin-report-product-dashboard',0
FROM eb_system_menus h
WHERE h.unique_auth='admin-index-index' AND h.menu_name='总部'
  AND h.type=1 AND h.is_del=0
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-report-product-dashboard' AND e.is_del=0)
  AND (SELECT COUNT(*) FROM eb_system_menus p
       WHERE p.unique_auth='admin-index-index' AND p.menu_name='总部'
         AND p.type=1 AND p.is_del=0)=1;

UPDATE eb_system_role r
SET r.rules=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM r.rules),''),
  (SELECT id FROM eb_system_menus WHERE unique_auth='admin-report-product-dashboard' AND is_del=0 LIMIT 1))
WHERE FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-report-group-management-dashboard' AND is_del=0 LIMIT 1),r.rules)>0
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE unique_auth='admin-report-product-dashboard' AND is_del=0 LIMIT 1),r.rules)=0;

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260821-001-platform-hq-product-dashboard-menu','平台总部商品看板菜单',
       '2026-08-21-平台总部商品看板菜单/02-正式升级.sql','', '', NOW(), 'codex-local',
       'registered product dashboard and inherited access for existing group dashboard roles'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM eb_database_upgrade_log WHERE upgrade_key='20260821-001-platform-hq-product-dashboard-menu');
