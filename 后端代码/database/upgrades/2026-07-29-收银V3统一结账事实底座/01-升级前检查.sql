-- upgrade_key: 20260729-007-cashier-v3-checkout-facts-v1
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @cf_db := DATABASE();
SET @cf_failures := 0;

SELECT COUNT(*) INTO @cf_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cf_db AND TABLE_NAME='eb_database_upgrade_log';

SET @cf_registered := 0;
SET @cf_registered_sql := IF(
  @cf_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @cf_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-007-cashier-v3-checkout-facts-v1''',
  'SELECT 0 INTO @cf_registered'
);
PREPARE cf_registered_stmt FROM @cf_registered_sql;
EXECUTE cf_registered_stmt;
DEALLOCATE PREPARE cf_registered_stmt;

SELECT COUNT(*) INTO @cf_dependency_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cf_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_request','eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_checkout_payment_draft','eb_cashier_v3_business_event',
    'eb_employee'
  )
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @cf_dependency_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cf_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_cashier_v3_checkout_request.request_id',
  'eb_cashier_v3_checkout_request.tenant_id',
  'eb_cashier_v3_checkout_request.organization_id',
  'eb_cashier_v3_checkout_request.store_id',
  'eb_cashier_v3_checkout_request.member_id',
  'eb_cashier_v3_checkout_request.operator_id',
  'eb_cashier_v3_checkout_request.business_date',
  'eb_cashier_v3_checkout_request.business_timezone',
  'eb_cashier_v3_checkout_line_draft.line_id',
  'eb_cashier_v3_checkout_line_draft.request_id',
  'eb_cashier_v3_checkout_payment_draft.payment_draft_id',
  'eb_cashier_v3_checkout_payment_draft.request_id',
  'eb_cashier_v3_business_event.event_no',
  'eb_cashier_v3_business_event.command_idempotency_key',
  'eb_cashier_v3_business_event.tenant_id',
  'eb_cashier_v3_business_event.organization_id',
  'eb_cashier_v3_business_event.store_id',
  'eb_cashier_v3_business_event.member_id',
  'eb_cashier_v3_business_event.operator_id',
  'eb_cashier_v3_business_event.business_date',
  'eb_cashier_v3_business_event.occurred_at',
  'eb_cashier_v3_business_event.settled_at',
  'eb_cashier_v3_business_event.recorded_at',
  'eb_employee.id',
  'eb_employee.employment_type_code',
  'eb_employee.employment_type_version'
);

SELECT COUNT(*) INTO @cf_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cf_db AND TABLE_NAME IN (
  'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
  'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
);

SET @cf_failures := @cf_failures
  + IF(@cf_upgrade_log_exists=1,0,1)
  + IF(@cf_registered=0,0,1)
  + IF(@cf_dependency_tables=5,0,1)
  + IF(@cf_dependency_columns=26,0,1)
  + IF(@cf_target_tables=0,0,1);

SELECT
  @cf_db AS db_name,
  VERSION() AS mysql_version,
  @cf_registered AS already_registered,
  @cf_dependency_tables AS dependency_table_count,
  @cf_dependency_columns AS dependency_column_count,
  @cf_target_tables AS existing_target_table_count,
  @cf_failures AS precheck_failure_count;

SET @cf_finish_sql := IF(
  @cf_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_CHECKOUT_FACT_PRECHECK_FAILED'
);
PREPARE cf_finish_stmt FROM @cf_finish_sql;
EXECUTE cf_finish_stmt;
DEALLOCATE PREPARE cf_finish_stmt;
