-- upgrade_key: 20260802-002-cashier-v3-recharge-debt-repayment-authority
-- Read-only postcheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rdr_db := DATABASE();

SELECT COUNT(*) INTO @rdr_tables_ok
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rdr_db AND TABLE_NAME IN (
  'eb_cashier_v3_recharge_debt_repayment',
  'eb_cashier_v3_recharge_debt_repayment_payment'
) AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @rdr_repayment_columns_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@rdr_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_repayment'
  AND COLUMN_NAME IN ('repayment_id','repayment_no','tenant_id','store_id','member_id','debt_id','recharge_id','command_idempotency_key','amount_cents','balance_ledger_id','status','version');
SELECT COUNT(*) INTO @rdr_payment_columns_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@rdr_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_repayment_payment'
  AND COLUMN_NAME IN ('repayment_id','payment_line_no','payment_method','amount_cents','collection_reference_snapshot','immutable_fingerprint');
SELECT @rdr_tables_ok AS table_count,@rdr_repayment_columns_ok AS repayment_columns,
  @rdr_payment_columns_ok AS payment_columns;
SET @rdr_abort := IF(@rdr_tables_ok=2 AND @rdr_repayment_columns_ok=12 AND @rdr_payment_columns_ok=6,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_RECHARGE_DEBT_REPAYMENT_POSTCHECK_FAILED');
PREPARE rdr_stmt FROM @rdr_abort; EXECUTE rdr_stmt; DEALLOCATE PREPARE rdr_stmt;
