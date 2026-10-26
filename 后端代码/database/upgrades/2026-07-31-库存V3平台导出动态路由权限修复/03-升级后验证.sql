-- upgrade_key: 20260731-004-inventory-v3-platform-export-route-auth
SET NAMES utf8mb4;

SELECT COUNT(*) AS expected_target_node_count
FROM eb_system_menus
WHERE type=1 AND is_del=0 AND methods='GET'
  AND api_url='product/inventory/v3/unified-query/export-task/<taskNo>'
  AND unique_auth='inventory-v3-platform-batch-export';

SELECT COUNT(*) AS legacy_node_count
FROM eb_system_menus
WHERE type=1 AND is_del=0 AND methods='GET'
  AND api_url='product/inventory/v3/unified-query/export-task/:taskNo';
