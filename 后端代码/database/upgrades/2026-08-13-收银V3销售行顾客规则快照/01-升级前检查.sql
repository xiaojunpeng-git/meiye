SET @db := DATABASE();
SELECT COUNT(*) AS authority_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('eb_cashier_v3_checkout_line_draft','eb_cashier_v3_sales_order_line','eb_cashier_v3_sale_fact');
