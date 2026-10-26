-- upgrade_key: 20260729-003-inventory-batch-query-facts
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @failures := 0;

SELECT COUNT(*) INTO @table_count
FROM information_schema.tables
WHERE table_schema=@db AND table_name IN ('eb_inventory_location','eb_inventory_batch_movement_fact');

SELECT COUNT(*) INTO @column_count
FROM information_schema.columns
WHERE table_schema=@db AND (
  (table_name='eb_inventory_stock' AND column_name='location_id') OR
  (table_name='eb_inventory_batch' AND column_name IN (
    'origin_batch_id','source_batch_id','received_business_date','product_name_snapshot',
    'sku_name_snapshot','product_code_snapshot','barcode_snapshot','brand_name_snapshot',
    'category_name_snapshot','source_order_no_snapshot','data_quality'
  ))
);

SELECT COUNT(*) INTO @bad_location
FROM eb_inventory_location
WHERE tenant_id='' OR organization_id='' OR organization_path='' OR owner_id=0
  OR location_code='' OR location_name='' OR location_type NOT IN ('HQ','BRANCH','STORE')
  OR is_default NOT IN (0,1) OR location_status NOT IN ('ACTIVE','FROZEN') OR version=0;

SELECT COUNT(*) INTO @bad_stock
FROM eb_inventory_stock
WHERE location_id=0 OR stock_status NOT IN ('GOOD','DEFECTIVE','QUARANTINE','FROZEN') OR version=0;

SELECT COUNT(*) INTO @bad_stock_scope
FROM eb_inventory_stock s
LEFT JOIN eb_inventory_location l ON l.id=s.location_id
WHERE l.id IS NULL OR l.tenant_id<>s.tenant_id
  OR (l.location_type='STORE' AND (l.store_id<>s.store_id OR l.owner_id<>s.store_id));

SELECT COUNT(*) INTO @bad_default_location
FROM (
  SELECT s.tenant_id,s.store_id
  FROM eb_inventory_stock s
  LEFT JOIN eb_inventory_location l
    ON l.tenant_id=s.tenant_id AND l.location_type='STORE' AND l.store_id=s.store_id
   AND l.is_default=1 AND l.location_status='ACTIVE'
  GROUP BY s.tenant_id,s.store_id
  HAVING COUNT(DISTINCT l.id)<>1
) bad_default;

SELECT COUNT(*) INTO @bad_batch
FROM eb_inventory_batch
WHERE stock_id=0 OR origin_batch_id=0 OR batch_no='' OR received_business_date IS NULL
  OR product_name_snapshot='' OR sku_name_snapshot='' OR source_order_no_snapshot=''
  OR data_quality NOT IN ('COMPLETE','HISTORICAL_UNKNOWN') OR version=0;

SELECT COUNT(*) INTO @bad_fact
FROM eb_inventory_batch_movement_fact
WHERE fact_key='' OR tenant_id='' OR organization_id='' OR organization_path=''
  OR location_id=0 OR stock_id=0 OR batch_id=0 OR direction NOT IN (-1,1)
  OR quantity_units=0 OR fact_status<>'SETTLED' OR source_type='' OR source_id=''
  OR source_detail_id='' OR business_date='0000-00-00' OR occurred_at=0 OR settled_at=0 OR recorded_at=0;

SELECT COUNT(*) INTO @bad_fact_scope
FROM eb_inventory_batch_movement_fact f
LEFT JOIN eb_inventory_stock s ON s.id=f.stock_id
LEFT JOIN eb_inventory_batch b ON b.id=f.batch_id
LEFT JOIN eb_inventory_location l ON l.id=f.location_id
WHERE s.id IS NULL OR b.id IS NULL OR l.id IS NULL OR b.stock_id<>f.stock_id
  OR s.tenant_id<>f.tenant_id OR s.location_id<>f.location_id OR s.store_id<>f.store_id
  OR s.organization_id<>f.organization_id OR s.organization_path<>f.organization_path
  OR l.tenant_id<>f.tenant_id;

SELECT COUNT(*) INTO @opening_mismatch
FROM eb_inventory_batch b
LEFT JOIN (
  SELECT batch_id,SUM(IF(direction=1,quantity_units,-CAST(quantity_units AS SIGNED))) AS qty
  FROM eb_inventory_batch_movement_fact GROUP BY batch_id
) f ON f.batch_id=b.id
WHERE b.available_quantity_units<>IFNULL(f.qty,0);

SELECT COUNT(*) INTO @stock_batch_mismatch
FROM eb_inventory_stock s
LEFT JOIN (
  SELECT stock_id,SUM(available_quantity_units) AS qty
  FROM eb_inventory_batch
  WHERE batch_status<>'VOIDED'
  GROUP BY stock_id
) b ON b.stock_id=s.id
WHERE s.available_quantity_units<>IFNULL(b.qty,0);

SET @failures := IF(@table_count=2,0,1)
  + IF(@column_count=12,0,1)
  + IF(@bad_location=0,0,1)
  + IF(@bad_stock=0,0,1)
  + IF(@bad_stock_scope=0,0,1)
  + IF(@bad_default_location=0,0,1)
  + IF(@bad_batch=0,0,1)
  + IF(@bad_fact=0,0,1)
  + IF(@bad_fact_scope=0,0,1)
  + IF(@opening_mismatch=0,0,1)
  + IF(@stock_batch_mismatch=0,0,1);

SELECT @table_count AS table_count,@column_count AS column_count,
       @bad_location AS bad_location_count,@bad_stock AS bad_stock_count,
       @bad_stock_scope AS bad_stock_scope_count,
       @bad_default_location AS bad_default_location_count,
       @bad_batch AS bad_batch_count,@bad_fact AS bad_fact_count,
       @bad_fact_scope AS bad_fact_scope_count,
       @opening_mismatch AS opening_mismatch_count,
       @stock_batch_mismatch AS stock_batch_mismatch_count,
       @failures AS verification_failure_count;

SET @finish_sql := IF(@failures=0,
  'SELECT ''VERIFY_OK'' AS verify_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory batch query verification failed''');
PREPARE stmt FROM @finish_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
