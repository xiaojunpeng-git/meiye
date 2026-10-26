-- upgrade_key: 20260730-002-inventory-batch-transfer
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT COUNT(*) INTO @table_count FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_batch_transfer_document','eb_inventory_batch_transfer_line');
SELECT COUNT(*) INTO @bad_documents FROM eb_inventory_batch_transfer_document
WHERE transfer_no='' OR idempotency_key='' OR request_fingerprint='' OR tenant_id='' OR from_location_id=0 OR to_location_id=0 OR from_location_id=to_location_id OR store_id=0 OR operator_id=0 OR document_status<>'CONFIRMED' OR business_date='0000-00-00' OR confirmed_at=0 OR recorded_at=0;
SELECT COUNT(*) INTO @bad_lines FROM eb_inventory_batch_transfer_line
WHERE document_id=0 OR from_stock_id=0 OR to_stock_id=0 OR from_batch_id=0 OR to_batch_id=0 OR origin_batch_id=0 OR quantity_units=0;
SELECT COUNT(*) INTO @broken_lineage
FROM eb_inventory_batch_transfer_line l
LEFT JOIN eb_inventory_batch sb ON sb.id=l.from_batch_id
LEFT JOIN eb_inventory_batch tb ON tb.id=l.to_batch_id
WHERE sb.id IS NULL OR tb.id IS NULL OR sb.stock_id<>l.from_stock_id OR tb.stock_id<>l.to_stock_id OR tb.origin_batch_id<>l.origin_batch_id OR tb.source_batch_id<>l.from_batch_id OR tb.unit_cost_cents<>l.unit_cost_cents;
SET @failures := IF(@table_count=2,0,1)+IF(@bad_documents=0,0,1)+IF(@bad_lines=0,0,1)+IF(@broken_lineage=0,0,1);
SELECT @table_count AS table_count,@bad_documents AS bad_document_count,@bad_lines AS bad_line_count,@broken_lineage AS broken_lineage_count,@failures AS verification_failure_count;
SET @finish_sql := IF(@failures=0,'SELECT ''VERIFY_OK'' AS verify_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory batch transfer verification failed''');
PREPARE stmt FROM @finish_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
