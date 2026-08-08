-- upgrade_key: 20260803-002-inventory-v3-excel-import
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @upgrade_key := '20260803-002-inventory-v3-excel-import';

SELECT COUNT(*) INTO @upgrade_log_exists
FROM information_schema.tables WHERE table_schema=@db AND table_name='eb_database_upgrade_log';
SET @upgrade_key_sql := IF(
  @upgrade_log_exists=1,
  CONCAT('SELECT COUNT(*) INTO @upgrade_key_registered FROM `eb_database_upgrade_log` WHERE `upgrade_key`=''', @upgrade_key, ''''),
  'SET @upgrade_key_registered:=1'
);
PREPARE upgrade_key_stmt FROM @upgrade_key_sql; EXECUTE upgrade_key_stmt; DEALLOCATE PREPARE upgrade_key_stmt;

SELECT COUNT(*) INTO @authority_tables
FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_batch_movement_fact','eb_inventory_business_document_no','eb_inventory_document_sequence');
SELECT COUNT(*) INTO @target_tables
FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_v3_import_record','eb_inventory_v3_import_error');
SET @precheck_ok := @upgrade_log_exists=1 AND @upgrade_key_registered=0 AND @authority_tables=3 AND @target_tables IN (0,2);
SELECT @upgrade_log_exists AS upgrade_log_exists,@upgrade_key_registered AS upgrade_key_registered,@authority_tables AS authority_table_count,@target_tables AS target_table_count,@precheck_ok AS precheck_ok;
SET @finish_sql := IF(@precheck_ok,'SELECT ''PRECHECK_OK'' AS precheck_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory v3 excel import precheck failed or partial schema detected''');
PREPARE stmt FROM @finish_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
