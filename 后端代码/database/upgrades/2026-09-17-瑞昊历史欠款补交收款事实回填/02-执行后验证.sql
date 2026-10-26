-- Run after 01-执行.php --execute.  Read-only reconciliation for this package.
SELECT
  COUNT(*) AS fact_rows,
  SUM(amount_cents) AS fact_amount_cents,
  MIN(business_date) AS first_business_date,
  MAX(business_date) AS last_business_date
FROM eb_cashier_v3_payment_fact
WHERE tenant_id='0'
  AND natural_key LIKE 'historical\\_debt\\_repayment:payment:%' ESCAPE '\\';

SELECT
  COUNT(*) AS event_rows,
  SUM(CASE WHEN pf.id IS NULL THEN 1 ELSE 0 END) AS event_without_fact
FROM eb_cashier_v3_business_event ev
LEFT JOIN eb_cashier_v3_payment_fact pf
  ON pf.tenant_id=ev.tenant_id
 AND pf.business_event_no=ev.event_no
 AND pf.natural_key LIKE 'historical\\_debt\\_repayment:payment:%' ESCAPE '\\'
WHERE ev.event_key LIKE 'historical-debt-repayment-fact-backfill-v1:%';

-- These rows must remain zero. Historical debt repayment never fabricates a
-- sale allocation merely to obtain product/category attribution.
SELECT COUNT(*) AS invented_sale_allocation_rows
FROM eb_cashier_v3_payment_sale_allocation_fact a
JOIN eb_cashier_v3_payment_fact pf
  ON pf.tenant_id=a.tenant_id AND pf.fact_id=a.payment_fact_id
WHERE pf.natural_key LIKE 'historical\\_debt\\_repayment:payment:%' ESCAPE '\\';
