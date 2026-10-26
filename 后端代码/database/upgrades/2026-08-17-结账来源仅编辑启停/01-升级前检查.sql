-- upgrade_key: 20260817-003-cashier-v3-source-settings-edit-status-only
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT id,parent_id,name,status,sort,require_secondary,version
FROM eb_cashier_v3_business_source
ORDER BY sort,id;

SELECT id,menu_name,api_url,methods,is_del,unique_auth
FROM eb_system_menus
WHERE type=1
  AND api_url='product/business-config/sources'
ORDER BY id;
