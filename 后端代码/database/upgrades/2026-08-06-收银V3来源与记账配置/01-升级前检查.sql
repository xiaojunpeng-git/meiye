-- upgrade_key: 20260806-002-cashier-v3-business-source-accounting-config
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT table_name
FROM information_schema.tables
WHERE table_schema=DATABASE()
  AND table_name IN (
    'eb_cash_source',
    'eb_cashier_v3_business_source',
    'eb_cashier_v3_payment_method_config',
    'eb_cashier_v3_business_config_audit',
    'eb_system_menus',
    'eb_system_role'
  )
ORDER BY table_name;

SELECT id,name,status FROM eb_cash_source ORDER BY id;

SELECT id,pid,type,menu_name,api_url,methods,unique_auth
FROM eb_system_menus
WHERE is_del=0 AND unique_auth='admin-setting-shop'
ORDER BY id;
