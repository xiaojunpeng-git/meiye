-- upgrade_key: 20261007-001-cross-industry-report-menu-names
-- 两条有效菜单必须各只有一条；如有改名或重复记录，先停下核对，不覆盖客户自定义名称。
SET NAMES utf8mb4;
SELECT unique_auth, COUNT(*) AS active_count, GROUP_CONCAT(CONCAT(id, ':', menu_name) ORDER BY id) AS current_names
FROM eb_system_menus
WHERE unique_auth IN (
  'admin-report-store-operations-cross_industry_customer_detail',
  'admin-report-store-operations-cross_industry_customer_summary'
) AND type=1 AND is_del=0
GROUP BY unique_auth;

SELECT upgrade_key, executed_at, result_note
FROM eb_database_upgrade_log
WHERE upgrade_key='20261007-001-cross-industry-report-menu-names';
