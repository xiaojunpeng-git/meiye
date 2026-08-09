-- upgrade_key: 20260806-001-cashier-v3-repayment-salesperson-snapshot
SET NAMES utf8mb4;

SELECT COUNT(*) INTO @repayment_salesperson_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_debt_repayment_draft',
    'eb_cashier_v3_debt_repayment',
    'eb_cashier_v3_recharge_debt_repayment'
  )
  AND ENGINE='InnoDB';

SELECT IF(@repayment_salesperson_tables=3,'PRECHECK_OK','PRECHECK_FAILED') AS precheck_result,
       @repayment_salesperson_tables AS authority_table_count;
