SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_service_record_void_operation','eb_cashier_v3_entitlement_reversal_fact')
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_service_record_void_operation','eb_cashier_v3_entitlement_reversal_fact')
  AND COLUMN_NAME IN ('service_fact_id','reason_snapshot','occurred_at','reversal_of','quantity')
ORDER BY TABLE_NAME, COLUMN_NAME;
