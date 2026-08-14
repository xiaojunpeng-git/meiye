-- upgrade_key: 20260814-002-cashier-v3-labor-performance-fee-split
SET @db := DATABASE();
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db
  AND ((TABLE_NAME='eb_cashier_v3_performance_fact' AND COLUMN_NAME='labor_fee_amount_cents')
    OR (TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='labor_fee_amount_cents'));
SELECT CASE WHEN COUNT(*)=2 THEN 'POSTCHECK_OK' ELSE 'POSTCHECK_FAILURE' END AS postcheck_result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db
  AND ((TABLE_NAME='eb_cashier_v3_performance_fact' AND COLUMN_NAME='labor_fee_amount_cents')
    OR (TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='labor_fee_amount_cents'));
