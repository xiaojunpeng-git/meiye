-- upgrade_key: 20260729-003-inventory-batch-query-facts
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT COUNT(*) INTO @base_tables
FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_stock','eb_inventory_batch');

SELECT COUNT(*) INTO @target_tables
FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_location','eb_inventory_batch_movement_fact');

SELECT COUNT(*) INTO @base_columns
FROM information_schema.columns
WHERE table_schema=@db AND (
  (table_name='eb_inventory_stock' AND column_name='location_id') OR
  (table_name='eb_inventory_batch' AND column_name IN (
    'origin_batch_id','source_batch_id','received_business_date','product_name_snapshot',
    'sku_name_snapshot','product_code_snapshot','barcode_snapshot','brand_name_snapshot',
    'category_name_snapshot','source_order_no_snapshot','data_quality'
  ))
);

SELECT COUNT(*) INTO @bad_existing_stock
FROM eb_inventory_stock
WHERE store_id=0 OR tenant_id='' OR organization_id='' OR organization_path='';

SET @precheck_ok := @base_tables=2
  AND @target_tables IN (0,2)
  AND @base_columns IN (0,12)
  AND NOT (@target_tables=0 AND @base_columns<>0)
  AND NOT (@target_tables=2 AND @base_columns<>12)
  AND @bad_existing_stock=0;

SELECT @base_tables AS base_table_count,
       @target_tables AS target_table_count,
       @base_columns AS target_base_column_count,
       @bad_existing_stock AS unsupported_existing_stock_count,
       @precheck_ok AS precheck_ok;

SET @finish_sql := IF(@precheck_ok,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory batch query precheck failed or partial schema detected''');
PREPARE stmt FROM @finish_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
