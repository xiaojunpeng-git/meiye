-- upgrade_key: 20260811-001-cashier-v3-card-operation-entitlement-credit-v1
SET NAMES utf8mb4;

SELECT COUNT(*) AS table_ready
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_card_operation_settlement'
  AND ENGINE='InnoDB';

SELECT COUNT(*) AS required_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_card_operation_settlement'
  AND COLUMN_NAME IN ('operation_id','sales_order_id','sales_order_line_id','entitlement_credit_cents','settlement_status');
