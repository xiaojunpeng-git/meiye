-- upgrade_key: 20260729-011-cashier-v3-sales-order-authority-v1
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @so_db := DATABASE();
SET @so_failures := 0;

SELECT COUNT(*) INTO @so_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@so_db AND TABLE_NAME='eb_database_upgrade_log';

SET @so_registered := 0;
SET @so_registered_sql := IF(
  @so_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @so_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-011-cashier-v3-sales-order-authority-v1''',
  'SELECT 0 INTO @so_registered'
);
PREPARE so_registered_stmt FROM @so_registered_sql;
EXECUTE so_registered_stmt;
DEALLOCATE PREPARE so_registered_stmt;

SELECT COUNT(*) INTO @so_dependency_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@so_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_request','eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_checkout_payment_draft','eb_cashier_v3_checkout_source_reference'
  )
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @so_dependency_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@so_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_cashier_v3_checkout_request.request_id',
  'eb_cashier_v3_checkout_request.tenant_id',
  'eb_cashier_v3_checkout_request.organization_id',
  'eb_cashier_v3_checkout_request.organization_path',
  'eb_cashier_v3_checkout_request.organization_name_snapshot',
  'eb_cashier_v3_checkout_request.store_id',
  'eb_cashier_v3_checkout_request.store_name_snapshot',
  'eb_cashier_v3_checkout_request.member_id',
  'eb_cashier_v3_checkout_request.member_name_snapshot',
  'eb_cashier_v3_checkout_request.operator_id',
  'eb_cashier_v3_checkout_request.operator_name_snapshot',
  'eb_cashier_v3_checkout_request.request_version',
  'eb_cashier_v3_checkout_request.request_status',
  'eb_cashier_v3_checkout_request.composition',
  'eb_cashier_v3_checkout_request.business_date',
  'eb_cashier_v3_checkout_request.business_timezone',
  'eb_cashier_v3_checkout_request.operation_occurred_at',
  'eb_cashier_v3_checkout_request.source_document_type',
  'eb_cashier_v3_checkout_request.source_document_id',
  'eb_cashier_v3_checkout_request.source_document_no',
  'eb_cashier_v3_checkout_request.sales_amount_cents',
  'eb_cashier_v3_checkout_request.receivable_amount_cents',
  'eb_cashier_v3_checkout_request.selected_payment_amount_cents',
  'eb_cashier_v3_checkout_request.balance_deduction_amount_cents',
  'eb_cashier_v3_checkout_request.debt_amount_cents',
  'eb_cashier_v3_checkout_request.cash_performance_amount_cents',
  'eb_cashier_v3_checkout_request.authority_fingerprint',
  'eb_cashier_v3_checkout_request.aggregate_fingerprint',
  'eb_cashier_v3_checkout_request.last_idempotency_key',
  'eb_cashier_v3_checkout_request.last_operation_fingerprint',
  'eb_cashier_v3_checkout_request.last_operation',
  'eb_cashier_v3_checkout_line_draft.line_id',
  'eb_cashier_v3_checkout_line_draft.request_id',
  'eb_cashier_v3_checkout_line_draft.draft_version',
  'eb_cashier_v3_checkout_line_draft.draft_status',
  'eb_cashier_v3_checkout_line_draft.tenant_id',
  'eb_cashier_v3_checkout_line_draft.store_id',
  'eb_cashier_v3_checkout_line_draft.member_id',
  'eb_cashier_v3_checkout_line_draft.line_role',
  'eb_cashier_v3_checkout_line_draft.authority_key',
  'eb_cashier_v3_checkout_line_draft.source_kind',
  'eb_cashier_v3_checkout_line_draft.source_type',
  'eb_cashier_v3_checkout_line_draft.source_id',
  'eb_cashier_v3_checkout_line_draft.entitlement_source_detail_id',
  'eb_cashier_v3_checkout_line_draft.source_version',
  'eb_cashier_v3_checkout_line_draft.project_id',
  'eb_cashier_v3_checkout_line_draft.project_version',
  'eb_cashier_v3_checkout_line_draft.quantity',
  'eb_cashier_v3_checkout_line_draft.original_amount_cents',
  'eb_cashier_v3_checkout_line_draft.discount_amount_cents',
  'eb_cashier_v3_checkout_line_draft.sale_amount_cents',
  'eb_cashier_v3_checkout_line_draft.entitlement_actual_amount_cents',
  'eb_cashier_v3_checkout_line_draft.source_name_snapshot',
  'eb_cashier_v3_checkout_line_draft.source_code_snapshot',
  'eb_cashier_v3_checkout_line_draft.project_name_snapshot',
  'eb_cashier_v3_checkout_line_draft.category_id_snapshot',
  'eb_cashier_v3_checkout_line_draft.category_name_snapshot',
  'eb_cashier_v3_checkout_line_draft.line_fingerprint',
  'eb_cashier_v3_checkout_line_draft.sort_no',
  'eb_cashier_v3_checkout_payment_draft.payment_draft_id',
  'eb_cashier_v3_checkout_payment_draft.request_id',
  'eb_cashier_v3_checkout_payment_draft.draft_version',
  'eb_cashier_v3_checkout_payment_draft.tenant_id',
  'eb_cashier_v3_checkout_payment_draft.store_id',
  'eb_cashier_v3_checkout_payment_draft.member_id',
  'eb_cashier_v3_checkout_payment_draft.operator_id',
  'eb_cashier_v3_checkout_payment_draft.payment_authority_key',
  'eb_cashier_v3_checkout_payment_draft.payment_method',
  'eb_cashier_v3_checkout_payment_draft.amount_cents',
  'eb_cashier_v3_checkout_payment_draft.external_transaction_no',
  'eb_cashier_v3_checkout_payment_draft.remark',
  'eb_cashier_v3_checkout_payment_draft.business_date',
  'eb_cashier_v3_checkout_payment_draft.business_timezone',
  'eb_cashier_v3_checkout_payment_draft.operation_occurred_at',
  'eb_cashier_v3_checkout_payment_draft.recorded_at',
  'eb_cashier_v3_checkout_payment_draft.operator_name_snapshot',
  'eb_cashier_v3_checkout_payment_draft.source_document_type',
  'eb_cashier_v3_checkout_payment_draft.source_document_id',
  'eb_cashier_v3_checkout_payment_draft.source_document_no',
  'eb_cashier_v3_checkout_payment_draft.payment_fingerprint',
  'eb_cashier_v3_checkout_payment_draft.draft_status',
  'eb_cashier_v3_checkout_payment_draft.sort_no',
  'eb_cashier_v3_checkout_source_reference.request_id',
  'eb_cashier_v3_checkout_source_reference.tenant_id',
  'eb_cashier_v3_checkout_source_reference.store_id',
  'eb_cashier_v3_checkout_source_reference.bound_request_version',
  'eb_cashier_v3_checkout_source_reference.source_kind',
  'eb_cashier_v3_checkout_source_reference.source_id',
  'eb_cashier_v3_checkout_source_reference.source_version',
  'eb_cashier_v3_checkout_source_reference.source_role',
  'eb_cashier_v3_checkout_source_reference.source_fingerprint'
);

SELECT COUNT(*) INTO @so_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@so_db
  AND TABLE_NAME IN ('eb_cashier_v3_sales_order','eb_cashier_v3_sales_order_line');

SET @so_failures := @so_failures
  + IF(@so_upgrade_log_exists=1,0,1)
  + IF(@so_registered=0,0,1)
  + IF(@so_dependency_tables=4,0,1)
  + IF(@so_dependency_columns=91,0,1)
  + IF(@so_target_tables=0,0,1);

SELECT
  @so_db AS db_name,
  VERSION() AS mysql_version,
  @so_registered AS already_registered,
  @so_dependency_tables AS dependency_table_count,
  @so_dependency_columns AS dependency_column_count,
  @so_target_tables AS existing_target_table_count,
  @so_failures AS precheck_failure_count;

SET @so_finish_sql := IF(
  @so_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_SALES_ORDER_PRECHECK_FAILED'
);
PREPARE so_finish_stmt FROM @so_finish_sql;
EXECUTE so_finish_stmt;
DEALLOCATE PREPARE so_finish_stmt;
