SET NAMES utf8mb4;
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_sales_manager_fact'
  AND COLUMN_NAME IN ('allocation_weight_numerator','allocation_weight_denominator','allocation_base_amount_cents','amount_cents')
ORDER BY ORDINAL_POSITION;
