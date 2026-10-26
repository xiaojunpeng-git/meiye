-- upgrade_key: 20260731-002-cashier-v3-inventory-submenu-permissions
SET NAMES utf8mb4;
SET @civp_db := DATABASE();
SELECT COUNT(*) INTO @civp_menu_table FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@civp_db AND TABLE_NAME='eb_system_menus' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @civp_conflicts FROM eb_system_menus
WHERE type=3 AND is_del=0 AND unique_auth LIKE 'cashier-inventory-%' AND api_url<>'';
SELECT @civp_menu_table AS menu_table_count,@civp_conflicts AS conflicting_inventory_permission_count;
SET @civp_abort := IF(@civp_menu_table=1 AND @civp_conflicts=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''cashier inventory submenu permission precheck failed''');
PREPARE civp_stmt FROM @civp_abort; EXECUTE civp_stmt; DEALLOCATE PREPARE civp_stmt;
