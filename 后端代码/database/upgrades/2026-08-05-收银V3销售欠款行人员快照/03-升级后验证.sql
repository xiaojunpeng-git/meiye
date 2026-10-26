-- upgrade_key: 20260805-008-cashier-v3-sales-debt-line-personnel-authority
SET NAMES utf8mb4;

SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_debt_item_personnel_authority';

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_debt_item_personnel_authority'
ORDER BY ORDINAL_POSITION;

SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_debt_item_personnel_authority'
GROUP BY INDEX_NAME
ORDER BY INDEX_NAME;

SELECT COUNT(*) AS invalid_rows
FROM eb_cashier_v3_debt_item_personnel_authority p
LEFT JOIN eb_store_debt_item i ON i.id = p.debt_item_id AND i.debt_id = p.debt_id
LEFT JOIN eb_cashier_v3_debt_authority a ON a.debt_id = p.debt_id
LEFT JOIN eb_cashier_v3_sales_order_line l ON l.order_line_id = p.order_line_id
WHERE i.id IS NULL OR a.id IS NULL OR l.id IS NULL
   OR p.line_debt_amount_cents <> ROUND(i.debt_amount * 100)
   OR p.tenant_id <> a.tenant_id OR p.store_id <> a.store_id OR p.member_id <> a.member_id;

SELECT 'POSTCHECK_OK' AS postcheck_result;
