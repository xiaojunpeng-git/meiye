-- upgrade_key: 20260803-001-inventory-v3-business-document-numbers
SET NAMES utf8mb4;
SET @db := DATABASE();
SELECT COUNT(*) INTO @sequence_table_ok
FROM information_schema.tables WHERE table_schema=@db AND table_name='eb_inventory_document_sequence';
SELECT COUNT(*) INTO @mapping_table_ok
FROM information_schema.tables WHERE table_schema=@db AND table_name='eb_inventory_business_document_no';
SELECT COUNT(*) INTO @sequence_columns
FROM information_schema.columns WHERE table_schema=@db AND table_name='eb_inventory_document_sequence'
  AND column_name IN ('tenant_id','document_type','business_date','current_value','created_at','updated_at');
SELECT COUNT(*) INTO @mapping_columns
FROM information_schema.columns WHERE table_schema=@db AND table_name='eb_inventory_business_document_no'
  AND column_name IN ('tenant_id','source_type','source_id','business_date','document_no','created_at');
SELECT COUNT(*) INTO @mapping_indexes
FROM information_schema.statistics WHERE table_schema=@db AND table_name='eb_inventory_business_document_no'
  AND index_name IN ('uk_tenant_source','uk_tenant_document_no');
SET @verify_ok := @sequence_table_ok=1 AND @mapping_table_ok=1 AND @sequence_columns=6 AND @mapping_columns=6 AND @mapping_indexes=5;
SELECT @sequence_table_ok AS sequence_table_ok,@mapping_table_ok AS mapping_table_ok,@sequence_columns AS sequence_column_count,@mapping_columns AS mapping_column_count,@mapping_indexes AS mapping_index_column_count,@verify_ok AS verify_ok;
SET @finish_sql := IF(@verify_ok,'SELECT ''VERIFY_OK'' AS verify_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory business document number verification failed''');
PREPARE stmt FROM @finish_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
