-- upgrade_key: 20260806-004-cashier-v3-old-card-entry-accounting-method
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SHOW TABLES LIKE 'eb_cashier_v3_payment_method_config';

SELECT id,code,default_name,display_name,status,sort,version
FROM eb_cashier_v3_payment_method_config
WHERE code='old_card_entry';
