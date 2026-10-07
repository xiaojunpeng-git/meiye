-- upgrade_key: 20261007-001-cross-industry-report-menu-names
SET NAMES utf8mb4;
SELECT id, unique_auth, menu_name, menu_path, type, is_del
FROM eb_system_menus
WHERE unique_auth IN (
  'admin-report-store-operations-cross_industry_customer_detail',
  'admin-report-store-operations-cross_industry_customer_summary'
)
ORDER BY unique_auth;

SELECT COUNT(*) AS correctly_named_active_menus
FROM eb_system_menus
WHERE type=1 AND is_del=0 AND (
  (unique_auth='admin-report-store-operations-cross_industry_customer_detail' AND menu_name='异业收客汇总表')
  OR (unique_auth='admin-report-store-operations-cross_industry_customer_summary' AND menu_name='异业收客明细表')
);
