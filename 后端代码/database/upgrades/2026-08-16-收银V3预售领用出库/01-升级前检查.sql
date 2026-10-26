-- upgrade_key: 20260816-005-cashier-v3-presale-claim-outbound
-- Read-only precheck. Run against the target instance before 02.
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_sales_order',
    'eb_cashier_v3_sales_order_line',
    'eb_inventory_stock',
    'eb_inventory_batch',
    'eb_inventory_batch_movement_fact'
  )
ORDER BY TABLE_NAME;

SELECT `id`,`menu_name`,`api_url`,`methods`,`unique_auth`,`menu_path`
FROM `eb_system_menus`
WHERE `type`=1 AND `is_del`=0
  AND (
    (`api_url`='product/v3/presale-claims' AND `methods`='GET')
    OR (`api_url`='product/v3/presale-claims/:id' AND `methods`='GET')
    OR (`api_url`='product/v3/presale-claims/claim' AND `methods`='POST')
    OR (`api_url`='product/v3/presale-claims/:id/void' AND `methods`='POST')
    OR `menu_path`='/admin/stock/presale-claim'
  )
ORDER BY `id`;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_presale_claimable_line',
    'eb_cashier_v3_presale_claim',
    'eb_cashier_v3_presale_claim_batch'
  )
ORDER BY TABLE_NAME;
