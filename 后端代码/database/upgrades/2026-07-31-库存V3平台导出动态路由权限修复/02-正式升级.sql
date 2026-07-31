-- upgrade_key: 20260731-004-inventory-v3-platform-export-route-auth
-- MySQL 5.6.51 compatible. Keep the existing row id so role grants remain valid.
SET NAMES utf8mb4;

UPDATE eb_system_menus
SET api_url = 'product/inventory/v3/unified-query/export-task/<taskNo>'
WHERE type=1 AND is_del=0 AND methods='GET'
  AND api_url='product/inventory/v3/unified-query/export-task/:taskNo'
  AND unique_auth='inventory-v3-platform-batch-export';

SELECT 'APPLY_OK' AS apply_result;
