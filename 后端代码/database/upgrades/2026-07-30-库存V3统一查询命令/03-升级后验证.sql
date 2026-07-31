-- upgrade_key: 20260730-010-inventory-v3-unified-query-command-receipt
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @failures := 0;

SELECT COUNT(*) INTO @table_count
FROM information_schema.tables
WHERE table_schema=@db AND table_name='eb_inventory_unified_query_command_receipt'
  AND engine='InnoDB' AND table_collation='utf8mb4_general_ci';

SELECT COUNT(*) INTO @column_count
FROM information_schema.columns
WHERE table_schema=@db AND table_name='eb_inventory_unified_query_command_receipt'
  AND column_name IN ('tenant_id','store_id','operator_id','page_code','action','idempotency_key','request_hash','status','result_json','business_no','created_at','updated_at');

SELECT COUNT(DISTINCT index_name) INTO @index_count
FROM information_schema.statistics
WHERE table_schema=@db AND table_name='eb_inventory_unified_query_command_receipt'
  AND index_name IN ('uk_scope_action_idempotency','idx_scope_time','idx_business_no');

SELECT COUNT(*) INTO @bad_rows
FROM eb_inventory_unified_query_command_receipt
WHERE tenant_id='' OR operator_id=0 OR page_code<> 'inventory_batch_stock'
  OR action='' OR idempotency_key='' OR request_hash='' OR status NOT IN ('PROCESSING','SUCCEEDED')
  OR created_at=0 OR updated_at=0;

SET @failures := IF(@table_count=1,0,1)
  + IF(@column_count=12,0,1)
  + IF(@index_count=3,0,1)
  + IF(@bad_rows=0,0,1);

SELECT @table_count AS table_count,@column_count AS column_count,@index_count AS index_count,
       @bad_rows AS bad_row_count,@failures AS verification_failure_count;

SET @finish_sql := IF(@failures=0,
  'SELECT ''VERIFY_OK'' AS verify_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory unified query command receipt verification failed''');
PREPARE stmt FROM @finish_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
