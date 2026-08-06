-- upgrade_key: 20260806-002-cashier-v3-business-source-accounting-config
SET NAMES utf8mb4;

SELECT table_name,table_rows
FROM information_schema.tables
WHERE table_schema=DATABASE()
  AND table_name IN (
    'eb_cashier_v3_business_source',
    'eb_cashier_v3_payment_method_config',
    'eb_cashier_v3_business_config_audit'
  ) ORDER BY table_name;

SELECT id,parent_id,name,status,sort,require_secondary,version
FROM eb_cashier_v3_business_source ORDER BY parent_id,sort,id;

SELECT code,default_name,display_name,status,sort,version
FROM eb_cashier_v3_payment_method_config ORDER BY sort,id;

SELECT COUNT(*) AS canonical_method_count
FROM eb_cashier_v3_payment_method_config
WHERE code IN ('unionpay','wechat','alipay','dianping_voucher','douyin_voucher','partner_collection','other_collection');

SELECT api_url,methods,unique_auth,is_del
FROM eb_system_menus
WHERE type=1 AND is_del=0 AND unique_auth='cashier-v3-business-config-manage'
ORDER BY api_url,methods;

SELECT COUNT(*) AS invalid_third_level_count
FROM eb_cashier_v3_business_source child
JOIN eb_cashier_v3_business_source parent ON parent.id=child.parent_id
WHERE parent.parent_id<>0;
