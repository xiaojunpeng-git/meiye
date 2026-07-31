-- upgrade_key: 20260730-002-inventory-batch-transfer
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT COUNT(*) INTO @base_tables
FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_location','eb_inventory_stock','eb_inventory_batch','eb_inventory_batch_movement_fact');

SELECT COUNT(*) INTO @target_tables
FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_batch_transfer_document','eb_inventory_batch_transfer_line');

SET @precheck_ok := @base_tables=4 AND @target_tables IN (0,2);
SELECT @base_tables AS base_table_count,@target_tables AS target_table_count,@precheck_ok AS precheck_ok;
SET @finish_sql := IF(@precheck_ok,'SELECT ''PRECHECK_OK'' AS precheck_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory batch transfer precheck failed or partial schema detected''');
PREPARE stmt FROM @finish_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
