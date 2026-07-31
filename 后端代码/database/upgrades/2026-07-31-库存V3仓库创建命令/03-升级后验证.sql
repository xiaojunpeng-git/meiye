-- upgrade_key: 20260731-004-inventory-v3-warehouse-create-command
-- Read-only postcheck; MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @iwc_db := DATABASE();

SELECT COUNT(*) INTO @iwc_creator_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@iwc_db AND TABLE_NAME='eb_inventory_location' AND COLUMN_NAME='created_by_admin_id'
  AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO';

SELECT COUNT(*) INTO @iwc_route
FROM eb_system_menus
WHERE type=1 AND auth_type=2 AND is_del=0 AND api_url='product/inventory/v3/locations' AND methods='POST'
  AND unique_auth='inventory-v3-platform-warehouse-manage';

SELECT @iwc_creator_column AS creator_audit_column_count,
       @iwc_route AS warehouse_manage_route_count;

SET @iwc_abort := IF(@iwc_creator_column=1 AND @iwc_route=1,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory warehouse create postcheck failed''');
PREPARE iwc_stmt FROM @iwc_abort; EXECUTE iwc_stmt; DEALLOCATE PREPARE iwc_stmt;
