-- upgrade_key: 20260916-003-cashier-v3-cart-line-detail-remark-snapshot
SET @db := DATABASE();
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_sales_order_line',
    'eb_cashier_v3_entitlement_service_fact'
  );
SELECT CASE WHEN COUNT(*) = 3 THEN 'PRECHECK_OK' ELSE 'STOP_MISSING_REQUIRED_TABLE' END AS precheck_result
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_sales_order_line',
    'eb_cashier_v3_entitlement_service_fact'
  );
