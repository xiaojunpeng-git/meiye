-- Run after 01-执行.php --execute. The source ledger rows must be archived,
-- while the authoritative pending-debt state and all V3 facts remain untouched.
SELECT COUNT(*) AS archived_orphan_rows,
       SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(raw_payload,'$.repay_amount')) AS DECIMAL(14,2))) AS archived_orphan_amount
FROM eb_mig_historical_debt_repay_orphan_backup
WHERE migration_key='historical-debt-repayment-orphan-isolation-v1';

SELECT COUNT(*) AS source_rows_remaining
FROM eb_store_debt_repay r
JOIN eb_mig_historical_debt_repay_orphan_backup b ON b.repay_id=r.id
WHERE b.migration_key='historical-debt-repayment-orphan-isolation-v1';

SELECT COUNT(*) AS accidental_payment_facts
FROM eb_cashier_v3_payment_fact
WHERE natural_key LIKE 'historical\_debt\_repayment:payment:%' ESCAPE '\\';
