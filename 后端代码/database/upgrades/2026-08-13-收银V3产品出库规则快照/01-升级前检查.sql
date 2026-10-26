SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_workspace_line','eb_cashier_v3_checkout_line_draft','eb_cashier_v3_sales_order_line','eb_cashier_v3_sale_fact')
  AND COLUMN_NAME = 'inventory_outbound_required';
