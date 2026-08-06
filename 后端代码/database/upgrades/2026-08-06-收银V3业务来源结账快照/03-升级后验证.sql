-- upgrade_key: 20260806-003-cashier-v3-checkout-business-source-snapshot
SET NAMES utf8mb4;

SELECT table_name,table_rows
FROM information_schema.tables
WHERE table_schema=DATABASE()
  AND table_name='eb_cashier_v3_checkout_business_source_selection';

SELECT table_name,column_name,column_type,column_default
FROM information_schema.columns
WHERE table_schema=DATABASE()
  AND (
    (table_name='eb_cashier_v3_sales_order' AND column_name LIKE 'business_source_%')
    OR (table_name='eb_user_recharge' AND column_name LIKE 'business_source_%')
  )
ORDER BY table_name,ordinal_position;

SELECT index_name,GROUP_CONCAT(column_name ORDER BY seq_in_index) AS index_columns
FROM information_schema.statistics
WHERE table_schema=DATABASE()
  AND table_name='eb_cashier_v3_sales_order'
  AND index_name='idx_business_source'
GROUP BY index_name;
