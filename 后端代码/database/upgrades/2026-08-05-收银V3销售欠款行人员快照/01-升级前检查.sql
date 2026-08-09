-- upgrade_key: 20260805-008-cashier-v3-sales-debt-line-personnel-authority
-- MySQL 5.6 compatible. Read-only precheck.
SET NAMES utf8mb4;

SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_debt_authority',
    'eb_store_debt_item',
    'eb_cashier_v3_sales_order_line',
    'eb_cashier_v3_debt_item_personnel_authority'
  )
ORDER BY TABLE_NAME;

SELECT COUNT(*) AS existing_authority_rows
FROM eb_cashier_v3_debt_authority;

SELECT 'PRECHECK_OK' AS precheck_result;
