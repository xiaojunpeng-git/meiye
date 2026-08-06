-- upgrade_key: 20260806-004-cashier-v3-old-card-entry-accounting-method
SET NAMES utf8mb4;

SELECT id,code,default_name,display_name,status,sort,version
FROM eb_cashier_v3_payment_method_config
WHERE code='old_card_entry';

SELECT COUNT(*) AS old_card_entry_config_count
FROM eb_cashier_v3_payment_method_config
WHERE code='old_card_entry';
