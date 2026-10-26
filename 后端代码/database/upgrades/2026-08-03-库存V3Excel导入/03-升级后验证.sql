-- upgrade_key: 20260803-002-inventory-v3-excel-import
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT COUNT(*) INTO @table_count FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_v3_import_record','eb_inventory_v3_import_error');
SELECT COUNT(*) INTO @record_columns FROM information_schema.columns
WHERE table_schema=@db AND table_name='eb_inventory_v3_import_record'
  AND column_name IN ('tenant_id','store_id','direction','file_hash','business_type','total_count','success_count','failure_count','document_no','status','operator_id','created_at','updated_at');
SELECT COUNT(*) INTO @error_columns FROM information_schema.columns
WHERE table_schema=@db AND table_name='eb_inventory_v3_import_error'
  AND column_name IN ('record_id','excel_row','error_message','row_snapshot','created_at');
SELECT COUNT(*) INTO @record_indexes FROM information_schema.statistics
WHERE table_schema=@db AND table_name='eb_inventory_v3_import_record'
  AND index_name IN ('uk_tenant_store_direction_file','idx_store_updated','idx_store_status');
SELECT COUNT(*) INTO @error_indexes FROM information_schema.statistics
WHERE table_schema=@db AND table_name='eb_inventory_v3_import_error' AND index_name='idx_record_row';
SELECT COUNT(*) INTO @bad_records FROM eb_inventory_v3_import_record
WHERE tenant_id='' OR store_id=0 OR direction NOT IN ('inbound','outbound') OR file_hash='' OR status NOT IN ('PROCESSING','SUCCEEDED','FAILED') OR operator_id=0;
SELECT COUNT(*) INTO @bad_errors FROM eb_inventory_v3_import_error
WHERE record_id=0 OR error_message='';
SET @verify_ok := @table_count=2 AND @record_columns=13 AND @error_columns=5 AND @record_indexes=10 AND @error_indexes=3 AND @bad_records=0 AND @bad_errors=0;
SELECT @table_count AS table_count,@record_columns AS record_column_count,@error_columns AS error_column_count,@record_indexes AS record_index_column_count,@error_indexes AS error_index_column_count,@bad_records AS bad_record_count,@bad_errors AS bad_error_count,@verify_ok AS verify_ok;
SET @finish_sql := IF(@verify_ok,'SELECT ''VERIFY_OK'' AS verify_result','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory v3 excel import verification failed''');
PREPARE stmt FROM @finish_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
