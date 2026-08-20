SET NAMES utf8mb4;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_entitlement_service_fact',
    'eb_cashier_v3_performance_fact',
    'eb_cashier_v3_service_record_void_operation'
  )
ORDER BY TABLE_NAME;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_performance_fact'
  AND COLUMN_NAME IN (
    'fact_id','fact_direction','reversal_of','performance_type',
    'employee_id','amount_cents','labor_fee_amount_cents'
  )
ORDER BY ORDINAL_POSITION;
