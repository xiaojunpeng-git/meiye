-- Read-only preflight for the selected instance. Do not run against an unnamed customer.
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_presale_claimable_line', 'eb_cashier_v3_presale_claim', 'eb_cashier_v3_presale_claimable_void', 'eb_database_upgrade_log')
ORDER BY TABLE_NAME;
SELECT `upgrade_key`, `executed_at`
FROM `eb_database_upgrade_log`
WHERE `upgrade_key`='20261015-001-presale-gift-claimable-void';
