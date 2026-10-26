SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_coupon_issue'
  AND COLUMN_NAME IN ('use_mode','order_tiers_json') ORDER BY COLUMN_NAME;
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_cashier_v3_checkout_order_coupon','eb_cashier_v3_order_balance_allocation');
SELECT COUNT(*) AS historical_order_mode_count FROM eb_store_coupon_issue WHERE use_mode=1;
