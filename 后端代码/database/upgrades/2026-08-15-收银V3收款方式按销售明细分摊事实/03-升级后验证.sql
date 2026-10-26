-- upgrade_key: 20260815-004-cashier-v3-payment-sale-allocation-fact
-- Read-only postcheck.
SET NAMES utf8mb4;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_payment_sale_allocation_fact'
  AND COLUMN_NAME IN (
    'allocation_fact_id', 'payment_fact_id', 'payment_method', 'sale_fact_id',
    'debt_amount_cents', 'allocation_base_amount_cents', 'amount_cents',
    'business_date', 'command_idempotency_key', 'immutable_fingerprint'
  )
ORDER BY ORDINAL_POSITION;

SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_payment_sale_allocation_fact'
GROUP BY INDEX_NAME
ORDER BY INDEX_NAME;

SELECT 'VERIFY_OK' AS verify_result;
