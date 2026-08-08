-- upgrade_key: 20260805-001-inventory-v3-menu-consolidation-hq-outbound
SET NAMES utf8mb4;

SELECT `unique_auth`,`is_show`,`is_del`
FROM `eb_system_menus`
WHERE `type`=1 AND `is_del`=0
  AND `unique_auth` IN ('admin-inventory-statistics','store-inventory-statistics');

SELECT `api_url`,`methods`,`unique_auth`,`is_show`,`is_del`
FROM `eb_system_menus`
WHERE `type`=1 AND `is_del`=0
  AND `api_url` IN (
    'product/inventory/v3/hq/outbound',
    'product/inventory/v3/hq/product-summary',
    'product/inventory/v3/hq/product-summary/:productId/detail'
  )
ORDER BY `api_url`,`methods`;
