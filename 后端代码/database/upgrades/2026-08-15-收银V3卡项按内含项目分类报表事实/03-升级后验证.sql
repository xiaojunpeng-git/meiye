-- upgrade_key: 20260815-002-card-sale-component-category-fact
SET NAMES utf8mb4;
SET @card_category_db := DATABASE();

SELECT COUNT(*) INTO @card_category_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@card_category_db
  AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact'
  AND ENGINE='InnoDB';

SELECT COUNT(DISTINCT INDEX_NAME) INTO @card_category_indexes
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@card_category_db
  AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact'
  AND INDEX_NAME IN ('uk_tenant_natural','uk_tenant_receipt_category','idx_scope_category','idx_sale_fact','idx_order_line');

SELECT COUNT(*) INTO @card_category_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@card_category_db
  AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact'
  AND COLUMN_NAME IN ('allocation_fact_id','natural_key','tenant_id','store_id','order_id','sale_fact_id','source_line_id','card_receipt_id','category_id_snapshot','configured_amount_cents','sale_amount_cents','cash_performance_amount_cents','business_date','business_event_no','immutable_fingerprint');

SELECT @card_category_table_count AS table_count,
  @card_category_indexes AS required_index_count,
  @card_category_columns AS required_column_count;

SET @card_category_sql := IF(
  @card_category_table_count=1 AND @card_category_indexes=5 AND @card_category_columns=15,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CARD_SALE_CATEGORY_ALLOCATION_POSTCHECK_FAILED'
);
PREPARE card_category_stmt FROM @card_category_sql;
EXECUTE card_category_stmt;
DEALLOCATE PREPARE card_category_stmt;
