-- upgrade_key: 20260812-001-store-operations-seven-reports-foundation
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @sor_db := DATABASE();
SET @sor_failures := 0;

SELECT COUNT(*) INTO @sor_fact_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@sor_db AND TABLE_NAME IN (
  'eb_cashier_v3_sale_fact','eb_cashier_v3_business_event','eb_database_upgrade_log'
) AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @sor_existing_targets
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@sor_db AND TABLE_NAME IN (
  'eb_cashier_v3_report_category_config',
  'eb_cashier_v3_report_category_config_audit',
  'eb_cashier_v3_report_sale_dimension_fact',
  'eb_cashier_v3_report_annotation',
  'eb_cashier_v3_report_annotation_audit'
);

SELECT @sor_db AS db_name,
  @sor_fact_tables AS required_authority_table_count,
  @sor_existing_targets AS existing_target_table_count;

SET @sor_failures := IF(@sor_fact_tables=3,0,1);
-- Existing tables are acceptable only when the postcheck can prove the full
-- contract. The formal upgrade uses CREATE IF NOT EXISTS and never drops data.
SET @sor_finish_sql := IF(@sor_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_STORE_OPERATIONS_REPORT_PRECHECK_FAILED');
PREPARE sor_finish_stmt FROM @sor_finish_sql; EXECUTE sor_finish_stmt; DEALLOCATE PREPARE sor_finish_stmt;
