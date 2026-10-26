-- upgrade_key: 20260731-001-inventory-v3-platform-access-scope
-- Read-only and MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @ivpa_db := DATABASE();

SELECT COUNT(*) INTO @ivpa_menu_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ivpa_db AND TABLE_NAME='eb_system_menus' AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @ivpa_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ivpa_db AND TABLE_NAME='eb_system_menus'
  AND COLUMN_NAME IN ('type','api_url','methods','auth_type','unique_auth','is_del');

SELECT COUNT(*) INTO @ivpa_conflicts
FROM eb_system_menus
WHERE type=1 AND auth_type=2 AND is_del=0
  AND (
    (api_url='product/inventory/v3/locations' AND methods='GET' AND unique_auth<>'inventory-v3-platform-batch-view')
    OR (api_url='product/inventory/v3/batch-stock' AND methods='GET' AND unique_auth<>'inventory-v3-platform-batch-view')
    OR (api_url='product/inventory/v3/unified-query/batch-stock' AND methods='GET' AND unique_auth<>'inventory-v3-platform-batch-view')
    OR (api_url='product/inventory/v3/unified-query/capabilities' AND methods='GET' AND unique_auth<>'inventory-v3-platform-query-manage')
    OR (api_url='product/inventory/v3/unified-query/commands' AND methods='POST' AND unique_auth<>'inventory-v3-platform-query-manage')
    OR (api_url='product/inventory/v3/unified-query/export-task/:taskNo' AND methods='GET' AND unique_auth<>'inventory-v3-platform-batch-export')
  );

SELECT COUNT(*) INTO @ivpa_cost_conflicts
FROM eb_system_menus
WHERE type=1 AND unique_auth='inventory-v3-platform-batch-cost' AND is_del=0
  AND NOT (auth_type=1 AND api_url='');

SELECT @ivpa_menu_table AS menu_table_count,
       @ivpa_columns AS required_column_count,
       @ivpa_conflicts AS conflicting_registered_endpoint_count,
       @ivpa_cost_conflicts AS conflicting_cost_capability_count;

SET @ivpa_abort := IF(@ivpa_menu_table=1 AND @ivpa_columns=6 AND @ivpa_conflicts=0 AND @ivpa_cost_conflicts=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory v3 platform access precheck failed''');
PREPARE ivpa_stmt FROM @ivpa_abort; EXECUTE ivpa_stmt; DEALLOCATE PREPARE ivpa_stmt;
