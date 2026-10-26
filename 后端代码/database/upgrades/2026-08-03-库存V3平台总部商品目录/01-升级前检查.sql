-- upgrade_key: 20260803-004-inventory-v3-platform-hq-catalog
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @upgrade_log_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=@db AND table_name='eb_database_upgrade_log');
SET @menu_table_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=@db AND table_name='eb_system_menus');
SET @registered_sql := IF(@upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @already_registered FROM `eb_database_upgrade_log` WHERE `upgrade_key`=''20260803-004-inventory-v3-platform-hq-catalog''',
  'SET @already_registered:=1');
PREPARE registered_stmt FROM @registered_sql; EXECUTE registered_stmt; DEALLOCATE PREPARE registered_stmt;
SET @anchor_sql := IF(@menu_table_exists=1,
  'SELECT COUNT(*) INTO @warehouse_manage_anchor FROM `eb_system_menus` WHERE `type`=1 AND `is_del`=0 AND `unique_auth`=''inventory-v3-platform-warehouse-manage''',
  'SET @warehouse_manage_anchor:=0');
PREPARE anchor_stmt FROM @anchor_sql; EXECUTE anchor_stmt; DEALLOCATE PREPARE anchor_stmt;
SET @precheck_ok := @upgrade_log_exists=1 AND @menu_table_exists=1 AND @already_registered=0 AND @warehouse_manage_anchor>=1;
SELECT DATABASE() AS current_database,@upgrade_log_exists AS upgrade_log_exists,@menu_table_exists AS menu_table_exists,@already_registered AS already_registered,@warehouse_manage_anchor AS warehouse_manage_anchor,@precheck_ok AS precheck_ok;
SET @finish_sql := IF(@precheck_ok,'SELECT ''PRECHECK_OK'' AS precheck_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''platform hq catalog upgrade precheck failed''');
PREPARE finish_stmt FROM @finish_sql; EXECUTE finish_stmt; DEALLOCATE PREPARE finish_stmt;
