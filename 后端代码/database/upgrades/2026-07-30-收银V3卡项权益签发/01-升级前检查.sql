-- upgrade_key: 20260730-023-cashier-v3-card-purchase-issuance-v1
SELECT DATABASE() AS current_database;
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_sales_order', 'eb_cashier_v3_sales_order_line',
    'eb_cashier_v3_payment_collection', 'eb_cashier_v3_entitlement_resource_version',
    'eb_store_order', 'eb_store_order_cart_info', 'eb_user_card_holder'
  )
ORDER BY TABLE_NAME;

SELECT INDEX_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_user_card_holder'
  AND COLUMN_NAME = 'card_no'
ORDER BY INDEX_NAME;
