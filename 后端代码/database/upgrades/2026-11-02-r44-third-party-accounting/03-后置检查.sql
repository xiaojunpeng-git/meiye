SELECT accounting_method,COUNT(*) AS order_count FROM eb_store_order GROUP BY accounting_method;
SELECT accounting_method,status,COUNT(*) AS order_count FROM eb_member_commerce_order GROUP BY accounting_method,status;
SELECT code,display_name,status,version FROM eb_cashier_v3_payment_method_config WHERE code='third_party';
