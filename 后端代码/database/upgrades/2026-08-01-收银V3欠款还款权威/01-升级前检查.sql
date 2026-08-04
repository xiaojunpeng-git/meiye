-- upgrade_key: 20260801-003-cashier-v3-debt-repayment-authority
SET NAMES utf8mb4;

SELECT TABLE_NAME, TABLE_TYPE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_store_debt','eb_store_debt_item','eb_cashier_v3_sales_order',
    'eb_cashier_v3_payment_fact','eb_cashier_v3_performance_fact',
    'eb_cashier_v3_business_event'
)
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COUNT(*) AS existing_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_debt_authority','eb_cashier_v3_debt_repayment_draft','eb_cashier_v3_debt_repayment',
    'eb_cashier_v3_debt_repayment_collection'
)
GROUP BY TABLE_NAME
ORDER BY TABLE_NAME;

SELECT 'PRECHECK_OK' AS precheck_result;
