-- Read-only verification; an empty audit table is valid before the first manual void.
SET NAMES utf8mb4;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME='eb_cashier_v3_presale_claimable_void'
ORDER BY ORDINAL_POSITION;
SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME='eb_cashier_v3_presale_claimable_void'
GROUP BY INDEX_NAME
ORDER BY INDEX_NAME;
SELECT `upgrade_key`, `executed_at`
FROM `eb_database_upgrade_log`
WHERE `upgrade_key`='20261015-001-presale-gift-claimable-void';
SELECT 'VERIFY_OK' AS verify_result;
