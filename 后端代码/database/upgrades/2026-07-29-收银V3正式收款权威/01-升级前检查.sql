-- upgrade_key: 20260729-012-cashier-v3-payment-collection-authority-v1
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @pc_db := DATABASE();
SET @pc_failures := 0;

SELECT COUNT(*) INTO @pc_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@pc_db AND TABLE_NAME='eb_database_upgrade_log';

SET @pc_registered := 0;
SET @pc_registered_sql := IF(
  @pc_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @pc_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-012-cashier-v3-payment-collection-authority-v1''',
  'SELECT 0 INTO @pc_registered'
);
PREPARE pc_registered_stmt FROM @pc_registered_sql;
EXECUTE pc_registered_stmt;
DEALLOCATE PREPARE pc_registered_stmt;

SELECT COUNT(*) INTO @pc_dependency_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@pc_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_request',
    'eb_cashier_v3_checkout_payment_draft',
    'eb_cashier_v3_sales_order'
  )
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @pc_dependency_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@pc_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_cashier_v3_checkout_request.request_id',
  'eb_cashier_v3_checkout_request.tenant_id',
  'eb_cashier_v3_checkout_request.organization_id',
  'eb_cashier_v3_checkout_request.organization_path',
  'eb_cashier_v3_checkout_request.organization_name_snapshot',
  'eb_cashier_v3_checkout_request.workspace_id',
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
  'eb_cashier_v3_checkout_request.recorded_at',
  'eb_cashier_v3_checkout_request.source_document_type',
  'eb_cashier_v3_checkout_request.source_document_id',
  'eb_cashier_v3_checkout_request.source_document_no',
  'eb_cashier_v3_checkout_request.sales_amount_cents',
  'eb_cashier_v3_checkout_request.receivable_amount_cents',
  'eb_cashier_v3_checkout_request.selected_payment_amount_cents',
  'eb_cashier_v3_checkout_request.balance_deduction_amount_cents',
  'eb_cashier_v3_checkout_request.balance_authority_key',
  'eb_cashier_v3_checkout_request.balance_account_id',
  'eb_cashier_v3_checkout_request.balance_account_version',
  'eb_cashier_v3_checkout_request.debt_amount_cents',
  'eb_cashier_v3_checkout_request.debt_authority_key',
  'eb_cashier_v3_checkout_request.debt_policy_version',
  'eb_cashier_v3_checkout_request.cash_performance_amount_cents',
  'eb_cashier_v3_checkout_request.entitlement_actual_amount_cents',
  'eb_cashier_v3_checkout_request.authority_snapshot_version',
  'eb_cashier_v3_checkout_request.authority_fingerprint',
  'eb_cashier_v3_checkout_request.aggregate_fingerprint',
  'eb_cashier_v3_checkout_request.creation_idempotency_key',
  'eb_cashier_v3_checkout_request.last_idempotency_key',
  'eb_cashier_v3_checkout_request.last_operation_fingerprint',
  'eb_cashier_v3_checkout_request.last_operation',
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
  'eb_cashier_v3_sales_order.order_id',
  'eb_cashier_v3_sales_order.order_no',
  'eb_cashier_v3_sales_order.immutable_fingerprint',
  'eb_cashier_v3_sales_order.tenant_id',
  'eb_cashier_v3_sales_order.organization_id',
  'eb_cashier_v3_sales_order.store_id',
  'eb_cashier_v3_sales_order.member_id',
  'eb_cashier_v3_sales_order.operator_id',
  'eb_cashier_v3_sales_order.command_idempotency_key',
  'eb_cashier_v3_sales_order.checkout_request_id',
  'eb_cashier_v3_sales_order.checkout_request_version',
  'eb_cashier_v3_sales_order.composition',
  'eb_cashier_v3_sales_order.business_date',
  'eb_cashier_v3_sales_order.sale_amount_cents',
  'eb_cashier_v3_sales_order.order_status',
  'eb_cashier_v3_sales_order.order_direction'
);

SELECT COUNT(*) INTO @pc_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@pc_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_payment_collection_batch',
    'eb_cashier_v3_payment_collection'
  );

SET @pc_failures := @pc_failures
  + IF(@pc_upgrade_log_exists=1,0,1)
  + IF(@pc_registered=0,0,1)
  + IF(@pc_dependency_tables=3,0,1)
  + IF(@pc_dependency_columns=80,0,1)
  + IF(@pc_target_tables=0,0,1);

SELECT
  @pc_db AS db_name,
  VERSION() AS mysql_version,
  @pc_registered AS already_registered,
  @pc_dependency_tables AS dependency_table_count,
  @pc_dependency_columns AS dependency_column_count,
  @pc_target_tables AS existing_target_table_count,
  @pc_failures AS precheck_failure_count;

SET @pc_finish_sql := IF(
  @pc_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_PAYMENT_COLLECTION_PRECHECK_FAILED'
);
PREPARE pc_finish_stmt FROM @pc_finish_sql;
EXECUTE pc_finish_stmt;
DEALLOCATE PREPARE pc_finish_stmt;
