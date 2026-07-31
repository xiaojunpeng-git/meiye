-- upgrade_key: 20260731-001-inventory-v3-platform-access-scope
-- Read-only exact verification. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @ivpa_db := DATABASE();

SELECT COUNT(*) INTO @ivpa_nodes
FROM eb_system_menus
WHERE type=1 AND auth_type=2 AND is_del=0 AND (
  (api_url='product/inventory/v3/locations' AND methods='GET' AND unique_auth='inventory-v3-platform-batch-view')
  OR (api_url='product/inventory/v3/batch-stock' AND methods='GET' AND unique_auth='inventory-v3-platform-batch-view')
  OR (api_url='product/inventory/v3/unified-query/batch-stock' AND methods='GET' AND unique_auth='inventory-v3-platform-batch-view')
  OR (api_url='product/inventory/v3/unified-query/capabilities' AND methods='GET' AND unique_auth='inventory-v3-platform-query-manage')
  OR (api_url='product/inventory/v3/unified-query/commands' AND methods='POST' AND unique_auth='inventory-v3-platform-query-manage')
  OR (api_url='product/inventory/v3/unified-query/export-task/:taskNo' AND methods='GET' AND unique_auth='inventory-v3-platform-batch-export')
);

SELECT COUNT(*) INTO @ivpa_cost_node
FROM eb_system_menus
WHERE type=1 AND auth_type=1 AND is_del=0 AND api_url=''
  AND unique_auth='inventory-v3-platform-batch-cost';

SELECT COUNT(*) INTO @ivpa_duplicate_routes
FROM (
  SELECT api_url,methods,COUNT(*) AS c
  FROM eb_system_menus
  WHERE type=1 AND auth_type=2 AND is_del=0 AND api_url LIKE 'product/inventory/v3/%'
  GROUP BY api_url,methods HAVING c>1
) ivpa_duplicates;

SELECT @ivpa_nodes AS registered_api_node_count,@ivpa_cost_node AS cost_capability_node_count,@ivpa_duplicate_routes AS duplicate_route_count;
SET @ivpa_abort := IF(@ivpa_nodes=6 AND @ivpa_cost_node=1 AND @ivpa_duplicate_routes=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory v3 platform access postcheck failed''');
PREPARE ivpa_stmt FROM @ivpa_abort; EXECUTE ivpa_stmt; DEALLOCATE PREPARE ivpa_stmt;
