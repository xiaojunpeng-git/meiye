-- upgrade_key: 20260806-003-cashier-v3-checkout-business-source-snapshot
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT table_name
FROM information_schema.tables
WHERE table_schema=DATABASE()
  AND table_name IN (
    'eb_cashier_v3_business_source',
    'eb_cashier_v3_checkout_business_source_selection',
    'eb_cashier_v3_sales_order',
    'eb_cashier_v3_recharge_checkout_request',
    'eb_user_recharge'
  )
ORDER BY table_name;

SELECT COUNT(*) AS active_source_count
FROM eb_cashier_v3_business_source
WHERE status=1;
