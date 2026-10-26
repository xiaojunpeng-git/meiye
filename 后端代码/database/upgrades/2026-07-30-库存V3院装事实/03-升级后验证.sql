-- upgrade_key: 20260730-003-inventory-salon-usage
SET NAMES utf8mb4;
SET @db := DATABASE();
SELECT COUNT(*) INTO @tables FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_salon_usage_document','eb_inventory_salon_usage_line');
SELECT COUNT(*) INTO @bad FROM eb_inventory_salon_usage_document
WHERE usage_no='' OR idempotency_key='' OR tenant_id='' OR store_id=0 OR project_id=0 OR location_id=0 OR operation_type NOT IN ('ISSUE','RETURN') OR document_status<>'SETTLED';
SET @sql := IF(@tables=2 AND @bad=0,'SELECT ''VERIFY_OK'' AS verify_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory salon usage verification failed''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
