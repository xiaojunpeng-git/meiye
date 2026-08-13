SET @db := DATABASE();
SELECT COUNT(*) AS rule_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('eb_cashier_v3_checkout_line_draft','eb_cashier_v3_sales_order_line','eb_cashier_v3_sale_fact')
AND COLUMN_NAME IN ('friend_counts_as_customer','is_presale');
