-- 四张权威表必须已经存在；缺少任一表时不得建立孤立的更正审计表。
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_sales_order',
    'eb_cashier_v3_payment_collection',
    'eb_cashier_v3_payment_fact',
    'eb_cashier_v3_payment_sale_allocation_fact'
  ) ORDER BY TABLE_NAME;
