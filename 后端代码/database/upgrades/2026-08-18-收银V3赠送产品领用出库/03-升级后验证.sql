-- upgrade_key: 20260818-003-cashier-v3-gift-product-claim-outbound
-- Read-only postcheck.
SET NAMES utf8mb4;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
    (TABLE_NAME='eb_cashier_v3_presale_claimable_line' AND COLUMN_NAME IN ('source_kind','gift_id','gift_item_id'))
    OR (TABLE_NAME='eb_cashier_v3_presale_claim' AND COLUMN_NAME='source_kind')
  )
ORDER BY TABLE_NAME, COLUMN_NAME;

SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME='eb_cashier_v3_presale_claimable_line'
  AND INDEX_NAME IN ('idx_scope_source_status_date','idx_gift_item')
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY INDEX_NAME;

SELECT 'VERIFY_OK' AS verify_result;
