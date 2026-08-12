-- upgrade_key: 20260813-002-cashier-v3-sales-manager-fact
SET NAMES utf8mb4;
SET @sm_db := DATABASE();
SET @sm_table := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sm_db AND TABLE_NAME='eb_cashier_v3_sales_manager_fact' AND ENGINE='InnoDB');
SET @sm_column := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sm_db AND TABLE_NAME='eb_cashier_v3_workspace_line' AND COLUMN_NAME='sales_manager_selections_json');
SET @sm_fact_columns := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sm_db AND TABLE_NAME='eb_cashier_v3_sales_manager_fact' AND COLUMN_NAME IN ('tenant_id','organization_id','store_id','member_id','order_id','source_line_id','business_date','sales_manager_employee_id','sales_manager_name_snapshot','immutable_fingerprint'));
SET @sm_indexes := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@sm_db AND TABLE_NAME='eb_cashier_v3_sales_manager_fact' AND INDEX_NAME IN ('uk_tenant_natural','uk_tenant_fact'));
SELECT @sm_table AS target_table_count, @sm_column AS workspace_column_count, @sm_fact_columns AS required_fact_column_count, @sm_indexes AS required_unique_index_count;
SELECT CASE WHEN @sm_table=1 AND @sm_column=1 AND @sm_fact_columns=10 AND @sm_indexes=2 THEN 'POSTCHECK_OK' ELSE 'POSTCHECK_FAILURE' END AS postcheck_result;
