-- upgrade_key: 20260730-001-inventory-batch-stock-count
SET NAMES utf8mb4;
SET @db := DATABASE();
SELECT COUNT(*) INTO @tables FROM information_schema.tables WHERE table_schema=@db AND table_name IN ('eb_inventory_stock_count_document','eb_inventory_stock_count_line');
SELECT COUNT(*) INTO @bad FROM eb_inventory_stock_count_document WHERE count_no='' OR idempotency_key='' OR request_fingerprint='' OR tenant_id='' OR organization_id='' OR organization_path='' OR location_id=0 OR store_id=0 OR operator_id=0 OR document_status<>'CONFIRMED' OR business_date='0000-00-00' OR confirmed_at=0 OR recorded_at=0;
SET @sql := IF(@tables=2 AND @bad=0,'SELECT ''VERIFY_OK'' AS verify_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory count verification failed''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
