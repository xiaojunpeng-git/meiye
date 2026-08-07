-- upgrade_key: 20260805-004-cashier-v3-order-lifecycle-authority
SET NAMES utf8mb4;
SET @lifecycle_db := DATABASE();

SELECT COUNT(*) INTO @lifecycle_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@lifecycle_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_order_lifecycle_operation',
    'eb_cashier_v3_order_reopen_draft',
    'eb_cashier_v3_order_lifecycle_financial_reversal',
    'eb_cashier_v3_order_lifecycle_debt_reversal',
    'eb_cashier_v3_order_lifecycle_benefit_reversal'
  )
  AND ENGINE='InnoDB';

SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,'|',INDEX_NAME)) INTO @lifecycle_unique_keys
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@lifecycle_db
  AND (
    (TABLE_NAME='eb_cashier_v3_order_lifecycle_operation' AND INDEX_NAME IN ('uk_operation_id','uk_operation_no','uk_tenant_command'))
    OR (TABLE_NAME='eb_cashier_v3_order_reopen_draft' AND INDEX_NAME IN ('uk_draft_id','uk_operation'))
    OR (TABLE_NAME='eb_cashier_v3_order_lifecycle_financial_reversal' AND INDEX_NAME IN ('uk_reversal_id','uk_operation','uk_tenant_command'))
    OR (TABLE_NAME='eb_cashier_v3_order_lifecycle_debt_reversal' AND INDEX_NAME IN ('uk_reversal_id','uk_operation_debt'))
    OR (TABLE_NAME='eb_cashier_v3_order_lifecycle_benefit_reversal' AND INDEX_NAME IN ('uk_reversal_id','uk_operation_receipt'))
  )
  AND NON_UNIQUE=0;

SELECT COUNT(*) INTO @lifecycle_invalid_rows
FROM eb_cashier_v3_order_lifecycle_operation
WHERE operation_id='' OR operation_no='' OR tenant_id='' OR source_order_id=''
   OR command_idempotency_key='' OR version<1 OR status='';

SELECT COUNT(*) INTO @lifecycle_audit_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@lifecycle_db AND TABLE_NAME='eb_cashier_v3_order_lifecycle_operation'
  AND COLUMN_NAME IN ('cash_refund_cents','reversed_cash_cents','restored_principal_cents','restored_bonus_cents','deducted_principal_cents','deducted_bonus_cents');

SELECT COUNT(*) INTO @lifecycle_financial_audit_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@lifecycle_db AND TABLE_NAME='eb_cashier_v3_order_lifecycle_financial_reversal'
  AND COLUMN_NAME IN ('cash_refund_cents','reversed_cash_cents','restored_principal_cents','restored_bonus_cents');

SELECT IF(@lifecycle_tables=5 AND @lifecycle_unique_keys=12 AND @lifecycle_audit_columns=6 AND @lifecycle_financial_audit_columns=4
  AND @lifecycle_invalid_rows=0,
  'POSTCHECK_OK', 'STOP_CASHIER_V3_ORDER_LIFECYCLE_POSTCHECK_FAILED') AS postcheck_result;
