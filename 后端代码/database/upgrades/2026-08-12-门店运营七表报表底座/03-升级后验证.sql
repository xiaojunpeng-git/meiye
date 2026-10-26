-- upgrade_key: 20260812-001-store-operations-seven-reports-foundation
-- Read-only postcheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @sor_db := DATABASE();
SET @sor_failures := 0;

SELECT COUNT(*) INTO @sor_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@sor_db AND TABLE_NAME IN (
  'eb_cashier_v3_report_category_config',
  'eb_cashier_v3_report_category_config_audit',
  'eb_cashier_v3_report_sale_dimension_fact',
  'eb_cashier_v3_report_annotation',
  'eb_cashier_v3_report_annotation_audit'
) AND ENGINE='InnoDB';

SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,':',INDEX_NAME)) INTO @sor_unique_indexes
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@sor_db AND (
  (TABLE_NAME='eb_cashier_v3_report_category_config' AND INDEX_NAME='uk_tenant_category') OR
  (TABLE_NAME='eb_cashier_v3_report_sale_dimension_fact' AND INDEX_NAME='uk_tenant_sale_fact') OR
  (TABLE_NAME='eb_cashier_v3_report_annotation' AND INDEX_NAME='uk_annotation_subject_field') OR
  (TABLE_NAME='eb_cashier_v3_report_annotation_audit' AND INDEX_NAME='uk_annotation_idempotency')
);

SELECT COUNT(*) INTO @sor_dimension_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@sor_db AND TABLE_NAME='eb_cashier_v3_report_sale_dimension_fact'
  AND COLUMN_NAME IN ('tenant_id','store_id','organization_id','member_id','order_id','sale_fact_id','source_line_id','business_date','item_id','item_name_snapshot','product_type_snapshot','category_id_snapshot','category_name_snapshot','category_parent_id_snapshot','category_parent_name_snapshot','category_path_snapshot','partner_name_snapshot','is_experience','immutable_fingerprint');

SELECT @sor_tables AS report_foundation_table_count,
  @sor_unique_indexes AS required_unique_index_count,
  @sor_dimension_columns AS sale_dimension_required_column_count;

SET @sor_failures := IF(@sor_tables=5,0,1)+IF(@sor_unique_indexes=4,0,1)+IF(@sor_dimension_columns=19,0,1);
SET @sor_finish_sql := IF(@sor_failures=0,'SELECT ''POSTCHECK_OK'' AS postcheck_result','SELECT * FROM STOP_STORE_OPERATIONS_REPORT_POSTCHECK_FAILED');
PREPARE sor_finish_stmt FROM @sor_finish_sql; EXECUTE sor_finish_stmt; DEALLOCATE PREPARE sor_finish_stmt;
