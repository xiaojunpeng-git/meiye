-- upgrade_key: 20260810-002-customer-lifecycle-first-course-attribution
-- Read-only postcheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @clf_db := DATABASE();
SET @clf_failures := 0;

SELECT COUNT(*) INTO @clf_source_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@clf_db AND TABLE_NAME='eb_cashier_v3_business_source'
  AND COLUMN_NAME='attribution_type' AND COLUMN_TYPE='varchar(24)';
SELECT COUNT(*) INTO @clf_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@clf_db AND TABLE_NAME IN ('eb_cashier_v3_customer_lifecycle_fact','eb_cashier_v3_customer_lifecycle_projection')
  AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @clf_fact_source_type_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@clf_db AND TABLE_NAME IN ('eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact','eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact')
  AND COLUMN_NAME='source_attribution_type_snapshot' AND COLUMN_TYPE='varchar(24)';
SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,'|',INDEX_NAME)) INTO @clf_unique_indexes
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@clf_db AND ((TABLE_NAME='eb_cashier_v3_customer_lifecycle_fact' AND INDEX_NAME='uk_tenant_natural') OR (TABLE_NAME='eb_cashier_v3_customer_lifecycle_projection' AND INDEX_NAME='uk_tenant_member'));

SET @clf_failures := IF(@clf_source_column=1,0,1)+IF(@clf_tables=2,0,1)+IF(@clf_fact_source_type_columns=4,0,1)+IF(@clf_unique_indexes=2,0,1);
SELECT @clf_source_column AS source_attribution_column_count,@clf_fact_source_type_columns AS fact_source_type_snapshot_count,@clf_tables AS lifecycle_table_count,@clf_unique_indexes AS lifecycle_unique_index_count,@clf_failures AS postcheck_failure_count;
SET @clf_finish_sql := IF(@clf_failures=0,'SELECT ''POSTCHECK_OK'' AS postcheck_result','SELECT * FROM STOP_CUSTOMER_LIFECYCLE_POSTCHECK_FAILED');
PREPARE clf_finish_stmt FROM @clf_finish_sql; EXECUTE clf_finish_stmt; DEALLOCATE PREPARE clf_finish_stmt;
