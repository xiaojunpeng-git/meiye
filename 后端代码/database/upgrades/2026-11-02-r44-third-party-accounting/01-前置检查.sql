SELECT TABLE_NAME,COLUMN_NAME,COLUMN_DEFAULT FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_store_order','eb_member_commerce_order') AND COLUMN_NAME='accounting_method';
SELECT code,display_name,status,version FROM eb_cashier_v3_payment_method_config WHERE code='third_party';
