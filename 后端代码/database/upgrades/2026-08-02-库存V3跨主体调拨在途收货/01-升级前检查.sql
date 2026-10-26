-- upgrade_key: 20260802-003-inventory-v3-cross-subject-transfer-receipt
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @upgrade_log_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=@db AND table_name='eb_database_upgrade_log');
SET @upgrade_key_check_sql := IF(
  @upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @upgrade_key_registered FROM `eb_database_upgrade_log` WHERE `upgrade_key`=''20260802-003-inventory-v3-cross-subject-transfer-receipt''',
  'SET @upgrade_key_registered:=1'
);
PREPARE upgrade_key_stmt FROM @upgrade_key_check_sql; EXECUTE upgrade_key_stmt; DEALLOCATE PREPARE upgrade_key_stmt;
SELECT COUNT(*) INTO @base_tables FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_location','eb_inventory_stock','eb_inventory_batch','eb_inventory_batch_movement_fact','eb_inventory_stock_request_document','eb_inventory_stock_request_line','eb_organization');
SELECT COUNT(*) INTO @target_tables FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_cross_transfer_document','eb_inventory_cross_transfer_line','eb_inventory_cross_transfer_batch_allocation','eb_inventory_stock_request_fulfillment');
SET @menu_table_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=@db AND table_name='eb_system_menus');
SET @precheck_ok := @upgrade_log_exists=1 AND @upgrade_key_registered=0 AND @base_tables=7 AND @menu_table_exists=1 AND @target_tables IN (0,4);
SELECT @upgrade_log_exists AS upgrade_log_exists,@upgrade_key_registered AS upgrade_key_registered,@base_tables AS base_table_count,@target_tables AS target_table_count,@precheck_ok AS precheck_ok;
SET @finish_sql := IF(@precheck_ok,'SELECT ''PRECHECK_OK'' AS precheck_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''cross subject inventory transfer precheck failed or partial schema detected''');
PREPARE stmt FROM @finish_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
