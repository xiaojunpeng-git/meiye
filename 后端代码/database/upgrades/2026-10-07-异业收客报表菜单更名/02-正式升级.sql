-- upgrade_key: 20261007-001-cross-industry-report-menu-names
-- 只更正平台导航展示名；稳定权限标识仍指向原报表代码，不交换数据或角色授权。
SET NAMES utf8mb4;
START TRANSACTION;

UPDATE eb_system_menus
SET menu_name='异业收客汇总表'
WHERE unique_auth='admin-report-store-operations-cross_industry_customer_detail'
  AND menu_name='异业收客明细表' AND type=1 AND is_del=0;

UPDATE eb_system_menus
SET menu_name='异业收客明细表'
WHERE unique_auth='admin-report-store-operations-cross_industry_customer_summary'
  AND menu_name='异业收客汇总表' AND type=1 AND is_del=0;

INSERT INTO eb_database_upgrade_log
  (upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
SELECT '20261007-001-cross-industry-report-menu-names','异业收客报表菜单更名',
  '2026-10-07-异业收客报表菜单更名/02-正式升级.sql','', '',NOW(),
  'codex-deploy','仅交换两条平台菜单显示名，报表代码和权限不变'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log
  WHERE upgrade_key='20261007-001-cross-industry-report-menu-names'
);

COMMIT;
