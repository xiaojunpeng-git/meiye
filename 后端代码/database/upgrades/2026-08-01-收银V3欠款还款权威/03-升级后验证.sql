-- upgrade_key: 20260801-003-cashier-v3-debt-repayment-authority
SET NAMES utf8mb4;

SELECT TABLE_NAME, COUNT(*) AS column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_debt_authority','eb_cashier_v3_debt_repayment_draft','eb_cashier_v3_debt_repayment',
    'eb_cashier_v3_debt_repayment_collection'
)
GROUP BY TABLE_NAME
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, INDEX_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_debt_authority','eb_cashier_v3_debt_repayment_draft','eb_cashier_v3_debt_repayment',
    'eb_cashier_v3_debt_repayment_collection'
)
  AND INDEX_NAME IN ('uk_debt_id','uk_draft_id','uk_repayment_id','uk_collection_id')
ORDER BY TABLE_NAME, INDEX_NAME;

SELECT 'POSTCHECK_OK' AS postcheck_result;
