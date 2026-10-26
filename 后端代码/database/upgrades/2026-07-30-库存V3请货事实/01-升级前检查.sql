-- upgrade_key: 20260730-002-inventory-stock-request
SET NAMES utf8mb4;
SET @db := DATABASE();
SELECT COUNT(*) INTO @base FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_location','eb_store_product','eb_store_product_attr_value');
SELECT COUNT(*) INTO @target FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_stock_request_document','eb_inventory_stock_request_line');
SET @ok := @base=3 AND @target IN (0,2);
SET @sql := IF(@ok,'SELECT ''PRECHECK_OK'' AS precheck_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory request precheck failed or partial schema exists''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
