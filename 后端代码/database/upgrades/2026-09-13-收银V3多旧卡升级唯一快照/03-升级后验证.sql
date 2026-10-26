-- upgrade_key: 20260913-001-cashier-v3-multi-card-upgrade-snapshot-v1
SET NAMES utf8mb4;
SET @mcu_db := DATABASE();
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@mcu_db AND TABLE_NAME IN (
  'eb_cashier_v3_multi_card_upgrade','eb_cashier_v3_multi_card_upgrade_source'
)
ORDER BY TABLE_NAME;
SELECT 'POSTCHECK_OK' AS postcheck_result;
