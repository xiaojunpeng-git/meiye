-- upgrade_key: 20260817-003-cashier-v3-source-settings-edit-status-only
SET NAMES utf8mb4;

SELECT id,menu_name,api_url,methods,is_del
FROM eb_system_menus
WHERE type=1
  AND api_url IN ('product/business-config/sources', 'product/business-config/sources/:id')
ORDER BY id;

SELECT COUNT(*) AS enabled_source_create_permission_count
FROM eb_system_menus
WHERE type=1
  AND is_del=0
  AND api_url='product/business-config/sources'
  AND methods='POST';

SELECT COUNT(*) AS source_row_count,
       SUM(status=1) AS enabled_source_count,
       SUM(status=0) AS disabled_source_count
FROM eb_cashier_v3_business_source;

SELECT upgrade_key,executed_at,result_note
FROM eb_database_upgrade_log
WHERE upgrade_key='20260817-003-cashier-v3-source-settings-edit-status-only';
