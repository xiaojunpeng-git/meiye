-- upgrade_key: 20260816-005-cashier-v3-presale-claim-outbound
-- Read-only postcheck.
SET NAMES utf8mb4;

SELECT TABLE_NAME, TABLE_COMMENT
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_presale_claimable_line',
    'eb_cashier_v3_presale_claim',
    'eb_cashier_v3_presale_claim_batch'
  )
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_presale_claimable_line',
    'eb_cashier_v3_presale_claim',
    'eb_cashier_v3_presale_claim_batch'
  )
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

SELECT `menu_name`,`api_url`,`methods`,`unique_auth`,`menu_path`
FROM `eb_system_menus`
WHERE `type`=1 AND `is_del`=0
  AND (
    (`api_url`='product/v3/presale-claims' AND `methods`='GET' AND `unique_auth`='inventory-v3-platform-batch-view')
    OR (`api_url`='product/v3/presale-claims/:id' AND `methods`='GET' AND `unique_auth`='inventory-v3-platform-batch-view')
    OR (`api_url`='product/v3/presale-claims/claim' AND `methods`='POST' AND `unique_auth`='inventory-v3-platform-warehouse-manage')
    OR (`api_url`='product/v3/presale-claims/:id/void' AND `methods`='POST' AND `unique_auth`='inventory-v3-platform-warehouse-manage')
    OR (`menu_path`='/admin/stock/presale-claim' AND `unique_auth`='admin-stock-manage')
  )
ORDER BY `api_url`,`methods`,`menu_path`;

SELECT 'VERIFY_OK' AS verify_result;
