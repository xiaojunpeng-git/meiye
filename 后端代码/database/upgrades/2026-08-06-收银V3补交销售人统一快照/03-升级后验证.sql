-- upgrade_key: 20260806-001-cashier-v3-repayment-salesperson-snapshot
SET NAMES utf8mb4;

SELECT COUNT(*) INTO @repayment_salesperson_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND COLUMN_NAME='salespeople_snapshot_json'
  AND DATA_TYPE='mediumtext'
  AND IS_NULLABLE='NO'
  AND TABLE_NAME IN (
    'eb_cashier_v3_debt_repayment_draft',
    'eb_cashier_v3_debt_repayment',
    'eb_cashier_v3_recharge_debt_repayment'
  );

SELECT IF(@repayment_salesperson_columns=3,'POSTCHECK_OK','POSTCHECK_FAILED') AS postcheck_result,
       @repayment_salesperson_columns AS snapshot_column_count;
