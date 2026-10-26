-- upgrade_key: 20260729-002-inventory-entitlement-completion
SET NAMES utf8mb4;
SET @inventory_db := DATABASE();
SET @inventory_failures := 0;

SELECT COUNT(*) INTO @inventory_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@inventory_db AND TABLE_NAME IN (
  'eb_inventory_shortage_policy',
  'eb_inventory_stock',
  'eb_inventory_batch',
  'eb_inventory_shortage_cost_cursor',
  'eb_inventory_consumption_receipt',
  'eb_inventory_batch_consumption_fact',
  'eb_inventory_shortage_fact',
  'eb_inventory_shortage_cost_adjustment'
);

SELECT COUNT(*) INTO @inventory_engine_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@inventory_db AND TABLE_NAME IN (
  'eb_inventory_shortage_policy',
  'eb_inventory_stock',
  'eb_inventory_batch',
  'eb_inventory_shortage_cost_cursor',
  'eb_inventory_consumption_receipt',
  'eb_inventory_batch_consumption_fact',
  'eb_inventory_shortage_fact',
  'eb_inventory_shortage_cost_adjustment'
) AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @inventory_unsigned_quantity_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@inventory_db AND COLUMN_TYPE='bigint(20) unsigned'
  AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
    'eb_inventory_stock.available_quantity_units',
    'eb_inventory_batch.available_quantity_units',
    'eb_inventory_batch.cost_allocated_quantity_units',
    'eb_inventory_shortage_cost_cursor.allocated_quantity_units',
    'eb_inventory_batch_consumption_fact.quantity_units',
    'eb_inventory_shortage_fact.shortage_quantity_units',
    'eb_inventory_shortage_cost_adjustment.allocated_quantity_units'
  );

SELECT COUNT(*) INTO @inventory_unique_contract_count
FROM (
  SELECT TABLE_NAME,INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@inventory_db AND TABLE_NAME IN (
    'eb_inventory_shortage_policy',
    'eb_inventory_stock',
    'eb_inventory_batch',
    'eb_inventory_shortage_cost_cursor',
    'eb_inventory_consumption_receipt',
    'eb_inventory_batch_consumption_fact',
    'eb_inventory_shortage_fact',
    'eb_inventory_shortage_cost_adjustment'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING non_unique=0 AND CONCAT(TABLE_NAME,'|',INDEX_NAME,'|',index_columns) IN (
    'eb_inventory_shortage_policy|uk_tenant_scope_project|tenant_id,policy_scope,project_id',
    'eb_inventory_stock|uk_tenant_store_sku_status|tenant_id,store_id,consumable_product_id,sku_id,stock_status',
    'eb_inventory_batch|uk_stock_batch_no|stock_id,batch_no',
    'eb_inventory_shortage_cost_cursor|uk_scope_recipe_stock_cost|tenant_id,store_id,recipe_id,stock_id,estimated_unit_cost_cents',
    'eb_inventory_consumption_receipt|uk_tenant_receipt_key|tenant_id,receipt_key',
    'eb_inventory_consumption_receipt|uk_tenant_idempotency|tenant_id,idempotency_key',
    'eb_inventory_batch_consumption_fact|uk_tenant_fact_key|tenant_id,fact_key',
    'eb_inventory_shortage_fact|uk_tenant_fact_key|tenant_id,fact_key',
    'eb_inventory_shortage_cost_adjustment|uk_tenant_adjustment_key|tenant_id,adjustment_key',
    'eb_inventory_shortage_cost_adjustment|uk_shortage_adjustment_version|shortage_fact_id,adjustment_version'
  )
) inventory_unique_indexes;

SET @inventory_failures := @inventory_failures
  + IF(@inventory_table_count=8,0,1)
  + IF(@inventory_engine_count=8,0,1)
  + IF(@inventory_unsigned_quantity_columns=7,0,1)
  + IF(@inventory_unique_contract_count=10,0,1);

SELECT COUNT(*) INTO @inventory_bad_policy
FROM eb_inventory_shortage_policy
WHERE tenant_id='' OR version=0
  OR policy_scope NOT IN ('MERCHANT','PROJECT')
  OR (policy_scope='MERCHANT' AND (project_id<>0 OR policy_value NOT IN ('deny_shortage','allow_shortage')))
  OR (policy_scope='PROJECT' AND (project_id=0 OR policy_value NOT IN ('inherit','deny_shortage','allow_shortage')));

SELECT COUNT(*) INTO @inventory_bad_stock
FROM eb_inventory_stock
WHERE tenant_id='' OR organization_id='' OR organization_path='' OR store_id=0
  OR consumable_product_id=0 OR sku_id=0 OR product_unique=''
  OR stock_status NOT IN ('GOOD','DEFECTIVE') OR quantity_scale>4 OR version=0;

SELECT COUNT(*) INTO @inventory_bad_batch
FROM eb_inventory_batch
WHERE stock_id=0 OR batch_no='' OR batch_status NOT IN ('ACTIVE','CLOSED','VOIDED') OR version=0;

SELECT COUNT(*) INTO @inventory_bad_shortage_cursor
FROM eb_inventory_shortage_cost_cursor
WHERE id=0 OR tenant_id='' OR store_id=0 OR stock_id=0 OR recipe_id=0 OR version=0;

SELECT COUNT(*) INTO @inventory_bad_receipt
FROM eb_inventory_consumption_receipt
WHERE receipt_key='' OR idempotency_key='' OR request_fingerprint='' OR tenant_id=''
  OR organization_id='' OR organization_path='' OR store_id=0 OR operator_id=0
  OR source_type='' OR source_id='' OR source_detail_id=''
  OR operation_type NOT IN ('CONSUME','REVERSAL')
  OR cost_complete_at_settlement NOT IN (0,1)
  OR business_date='0000-00-00' OR occurred_at=0 OR settled_at=0 OR recorded_at=0;

SELECT COUNT(*) INTO @inventory_bad_batch_fact
FROM eb_inventory_batch_consumption_fact
WHERE fact_key='' OR receipt_id=0 OR direction NOT IN (-1,1) OR tenant_id=''
  OR store_id=0 OR source_type='' OR source_id='' OR source_detail_id='' OR line_id=''
  OR project_id=0 OR project_name_snapshot='' OR recipe_id=0 OR recipe_version=0 OR recipe_formula_hash=''
  OR policy_value NOT IN ('deny_shortage','allow_shortage') OR policy_version=0
  OR consumable_product_id=0 OR sku_id=0 OR stock_id=0 OR batch_id=0
  OR quantity_scale>4 OR quantity_units=0 OR cost_cursor_after<cost_cursor_before
  OR batch_version_before=0 OR batch_version_after<=batch_version_before;

SELECT COUNT(*) INTO @inventory_bad_shortage_fact
FROM eb_inventory_shortage_fact
WHERE fact_key='' OR receipt_id=0 OR direction NOT IN (-1,1) OR tenant_id=''
  OR store_id=0 OR source_type='' OR source_id='' OR source_detail_id='' OR line_id=''
  OR project_id=0 OR project_name_snapshot='' OR recipe_id=0 OR recipe_version=0 OR recipe_formula_hash=''
  OR consumable_product_id=0 OR sku_id=0 OR stock_id=0
  OR quantity_scale>4 OR shortage_quantity_units=0 OR cost_cursor_after<=cost_cursor_before
  OR policy_value<>'allow_shortage' OR policy_version=0;

SELECT COUNT(*) INTO @inventory_bad_cost_adjustment
FROM eb_inventory_shortage_cost_adjustment
WHERE adjustment_key='' OR shortage_fact_id=0 OR direction NOT IN (-1,1)
  OR tenant_id='' OR store_id=0 OR adjustment_version=0 OR allocated_quantity_units=0
  OR source_type='' OR source_id='' OR request_fingerprint='' OR occurred_at=0 OR recorded_at=0;

SET @inventory_failures := @inventory_failures
  + IF(@inventory_bad_policy=0,0,1)
  + IF(@inventory_bad_stock=0,0,1)
  + IF(@inventory_bad_batch=0,0,1)
  + IF(@inventory_bad_shortage_cursor=0,0,1)
  + IF(@inventory_bad_receipt=0,0,1)
  + IF(@inventory_bad_batch_fact=0,0,1)
  + IF(@inventory_bad_shortage_fact=0,0,1)
  + IF(@inventory_bad_cost_adjustment=0,0,1);

SELECT
  @inventory_table_count AS table_count,
  @inventory_engine_count AS innodb_table_count,
  @inventory_unsigned_quantity_columns AS unsigned_quantity_column_count,
  @inventory_unique_contract_count AS unique_contract_count,
  @inventory_failures AS verification_failure_count;

SET @inventory_finish_sql := IF(
  @inventory_failures=0,
  'SELECT ''VERIFY_OK'' AS verify_result',
  'SELECT * FROM STOP_INVENTORY_COMPLETION_VERIFY_FAILED'
);
PREPARE inventory_finish_stmt FROM @inventory_finish_sql;
EXECUTE inventory_finish_stmt;
DEALLOCATE PREPARE inventory_finish_stmt;
