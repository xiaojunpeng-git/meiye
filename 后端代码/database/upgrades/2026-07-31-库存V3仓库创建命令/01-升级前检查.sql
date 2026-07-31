-- upgrade_key: 20260731-004-inventory-v3-warehouse-create-command
-- Read-only precheck; MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @iwc_db := DATABASE();

SELECT COUNT(*) INTO @iwc_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@iwc_db AND TABLE_NAME IN ('eb_inventory_location','eb_system_menus','eb_system_store','eb_organization_store') AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @iwc_location_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@iwc_db AND TABLE_NAME='eb_inventory_location'
  AND COLUMN_NAME IN ('tenant_id','organization_id','organization_path','location_code','location_name','store_id','is_default','location_status','version','created_at','updated_at');

SELECT COUNT(*) INTO @iwc_conflicting_route
FROM eb_system_menus
WHERE type=1 AND is_del=0 AND api_url='product/inventory/v3/locations' AND methods='POST'
  AND unique_auth<>'inventory-v3-platform-warehouse-manage';

SELECT @iwc_tables AS required_table_count,
       @iwc_location_columns AS required_location_column_count,
       @iwc_conflicting_route AS conflicting_create_route_count;

SET @iwc_abort := IF(@iwc_tables=4 AND @iwc_location_columns=11 AND @iwc_conflicting_route=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory warehouse create precheck failed''');
PREPARE iwc_stmt FROM @iwc_abort; EXECUTE iwc_stmt; DEALLOCATE PREPARE iwc_stmt;
