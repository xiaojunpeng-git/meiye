-- upgrade_key: 20260818-003-cashier-v3-gift-product-claim-outbound
-- Read-only precheck. Run against the target instance before 02.
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_direct_gift_authority',
    'eb_cashier_v3_direct_gift_item',
    'eb_cashier_v3_presale_claimable_line',
    'eb_cashier_v3_presale_claim',
    'eb_inventory_stock',
    'eb_inventory_batch',
    'eb_inventory_batch_movement_fact'
  )
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_presale_claimable_line', 'eb_cashier_v3_presale_claim')
  AND COLUMN_NAME IN ('source_kind', 'gift_id', 'gift_item_id')
ORDER BY TABLE_NAME, COLUMN_NAME;
