-- upgrade_key: 20261007-001-cross-industry-report-menu-names
-- 仅在平台前端已回退旧标签后执行；保留升级日志作为历史审计记录。
SET NAMES utf8mb4;
START TRANSACTION;

UPDATE eb_system_menus
SET menu_name='异业收客明细表'
WHERE unique_auth='admin-report-store-operations-cross_industry_customer_detail'
  AND menu_name='异业收客汇总表' AND type=1 AND is_del=0;

UPDATE eb_system_menus
SET menu_name='异业收客汇总表'
WHERE unique_auth='admin-report-store-operations-cross_industry_customer_summary'
  AND menu_name='异业收客明细表' AND type=1 AND is_del=0;

COMMIT;
