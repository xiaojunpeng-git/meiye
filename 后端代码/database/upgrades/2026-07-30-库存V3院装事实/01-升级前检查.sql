-- upgrade_key: 20260730-003-inventory-salon-usage
SET NAMES utf8mb4;
SET @db := DATABASE();
SELECT COUNT(*) INTO @base FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_location','eb_inventory_stock','eb_inventory_batch','eb_inventory_batch_movement_fact');
SELECT COUNT(*) INTO @target FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_salon_usage_document','eb_inventory_salon_usage_line');
SET @sql := IF(@base=4 AND @target IN (0,2),'SELECT ''PRECHECK_OK'' AS precheck_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory salon usage precheck failed or partial schema exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
