SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_checkout_line_draft', 'eb_cashier_v3_sales_order_line')
  AND COLUMN_NAME = 'card_purchase_snapshot_json'
ORDER BY TABLE_NAME;
