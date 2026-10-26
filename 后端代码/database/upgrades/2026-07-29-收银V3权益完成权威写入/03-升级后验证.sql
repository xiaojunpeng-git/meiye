-- upgrade_key: 20260729-014-cashier-v3-entitlement-completion-persistence-v1
SET NAMES utf8mb4;
SET @ecp_db := DATABASE();
SET @ecp_failures := 0;

SELECT COUNT(*) INTO @ecp_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME IN (
  'eb_cashier_v3_entitlement_completion_receipt',
  'eb_cashier_v3_entitlement_writeoff_fact',
  'eb_cashier_v3_entitlement_service_fact'
);
SELECT COUNT(*) INTO @ecp_engine_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME IN (
  'eb_cashier_v3_entitlement_completion_receipt',
  'eb_cashier_v3_entitlement_writeoff_fact',
  'eb_cashier_v3_entitlement_service_fact'
) AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @ecp_required_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ecp_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_cashier_v3_entitlement_completion_receipt.id',
  'eb_cashier_v3_entitlement_completion_receipt.receipt_id',
  'eb_cashier_v3_entitlement_completion_receipt.tenant_id',
  'eb_cashier_v3_entitlement_completion_receipt.checkout_request_id',
  'eb_cashier_v3_entitlement_completion_receipt.command_idempotency_key',
  'eb_cashier_v3_entitlement_completion_receipt.plan_fingerprint',
  'eb_cashier_v3_entitlement_completion_receipt.organization_id',
  'eb_cashier_v3_entitlement_completion_receipt.store_id',
  'eb_cashier_v3_entitlement_completion_receipt.member_id',
  'eb_cashier_v3_entitlement_completion_receipt.operator_id',
  'eb_cashier_v3_entitlement_completion_receipt.workspace_id',
  'eb_cashier_v3_entitlement_completion_receipt.state_context_id',
  'eb_cashier_v3_entitlement_completion_receipt.status',
  'eb_cashier_v3_entitlement_completion_receipt.result_json',
  'eb_cashier_v3_entitlement_completion_receipt.settled_at',
  'eb_cashier_v3_entitlement_writeoff_fact.writeoff_id',
  'eb_cashier_v3_entitlement_writeoff_fact.natural_key',
  'eb_cashier_v3_entitlement_writeoff_fact.checkout_request_id',
  'eb_cashier_v3_entitlement_writeoff_fact.source_line_id',
  'eb_cashier_v3_entitlement_writeoff_fact.holder_id',
  'eb_cashier_v3_entitlement_writeoff_fact.source_detail_id',
  'eb_cashier_v3_entitlement_writeoff_fact.quantity',
  'eb_cashier_v3_entitlement_writeoff_fact.actual_entitlement_amount_cents',
  'eb_cashier_v3_entitlement_writeoff_fact.service_object',
  'eb_cashier_v3_entitlement_writeoff_fact.is_experience',
  'eb_cashier_v3_entitlement_writeoff_fact.craftsmen_snapshot_json',
  'eb_cashier_v3_entitlement_writeoff_fact.occupation_snapshot_json',
  'eb_cashier_v3_entitlement_service_fact.service_fact_id',
  'eb_cashier_v3_entitlement_service_fact.natural_key',
  'eb_cashier_v3_entitlement_service_fact.checkout_request_id',
  'eb_cashier_v3_entitlement_service_fact.source_line_id',
  'eb_cashier_v3_entitlement_service_fact.project_id',
  'eb_cashier_v3_entitlement_service_fact.quantity',
  'eb_cashier_v3_entitlement_service_fact.service_object',
  'eb_cashier_v3_entitlement_service_fact.is_experience',
  'eb_cashier_v3_entitlement_service_fact.primary_craftsman_staff_id',
  'eb_cashier_v3_entitlement_service_fact.craftsmen_snapshot_json',
  'eb_cashier_v3_entitlement_service_fact.service_status'
);

SELECT COUNT(*) INTO @ecp_unique_count
FROM (
  SELECT TABLE_NAME,INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME IN (
    'eb_cashier_v3_entitlement_completion_receipt',
    'eb_cashier_v3_entitlement_writeoff_fact',
    'eb_cashier_v3_entitlement_service_fact'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING non_unique=0 AND CONCAT(TABLE_NAME,'|',INDEX_NAME,'|',index_columns) IN (
    'eb_cashier_v3_entitlement_completion_receipt|uk_receipt_id|receipt_id',
    'eb_cashier_v3_entitlement_completion_receipt|uk_tenant_checkout_request|tenant_id,checkout_request_id',
    'eb_cashier_v3_entitlement_completion_receipt|uk_tenant_command_idem|tenant_id,command_idempotency_key',
    'eb_cashier_v3_entitlement_writeoff_fact|uk_writeoff_id|writeoff_id',
    'eb_cashier_v3_entitlement_writeoff_fact|uk_tenant_natural|tenant_id,natural_key',
    'eb_cashier_v3_entitlement_writeoff_fact|uk_tenant_checkout_line|tenant_id,checkout_request_id,source_line_id',
    'eb_cashier_v3_entitlement_service_fact|uk_service_fact_id|service_fact_id',
    'eb_cashier_v3_entitlement_service_fact|uk_tenant_natural|tenant_id,natural_key',
    'eb_cashier_v3_entitlement_service_fact|uk_tenant_checkout_line|tenant_id,checkout_request_id,source_line_id'
  )
) ecp_unique_indexes;

SELECT COUNT(*) INTO @ecp_bad_receipt
FROM eb_cashier_v3_entitlement_completion_receipt
WHERE receipt_id NOT REGEXP '^ECR-[0-9a-f]{40}$' OR tenant_id=''
  OR checkout_request_id='' OR command_idempotency_key=''
  OR plan_fingerprint NOT REGEXP '^[0-9a-f]{64}$' OR business_event_no=''
  OR workspace_id='' OR state_context_id='' OR organization_id=''
  OR store_id=0 OR member_id=0 OR operator_id=0 OR business_date='0000-00-00'
  OR occurred_at=0 OR settled_at=0 OR recorded_at=0 OR line_count=0 OR service_quantity=0
  OR status NOT IN ('processing','completed')
  OR (status='processing' AND (result_json<>'' OR completed_at<>0))
  OR (status='completed' AND (result_json='' OR completed_at=0));
SELECT COUNT(*) INTO @ecp_bad_writeoff
FROM eb_cashier_v3_entitlement_writeoff_fact
WHERE writeoff_id NOT REGEXP '^EWO-[0-9a-f]{40}$' OR natural_key=''
  OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$' OR tenant_id=''
  OR checkout_request_id='' OR source_line_id='' OR holder_id=0 OR origin_order_id=0
  OR source_detail_id=0 OR project_id=0 OR source_version_snapshot=0
  OR detail_version_snapshot=0 OR quantity=0 OR total_purchase_times_snapshot=0
  OR service_object NOT IN ('self','friend') OR is_experience NOT IN (0,1)
  OR primary_craftsman_staff_id=0 OR craftsmen_snapshot_json=''
  OR occupation_snapshot_json='' OR status<>'effective';
SELECT COUNT(*) INTO @ecp_bad_service
FROM eb_cashier_v3_entitlement_service_fact
WHERE service_fact_id NOT REGEXP '^ESF-[0-9a-f]{40}$' OR natural_key=''
  OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$' OR tenant_id=''
  OR checkout_request_id='' OR source_line_id='' OR project_id=0 OR quantity=0
  OR service_object NOT IN ('self','friend') OR is_experience NOT IN (0,1)
  OR primary_craftsman_staff_id=0 OR craftsmen_snapshot_json=''
  OR service_status<>'completed';

SET @ecp_failures := @ecp_failures
  + IF(@ecp_table_count=3,0,1)
  + IF(@ecp_engine_count=3,0,1)
  + IF(@ecp_required_columns=38,0,1)
  + IF(@ecp_unique_count=9,0,1)
  + IF(@ecp_bad_receipt=0,0,1)
  + IF(@ecp_bad_writeoff=0,0,1)
  + IF(@ecp_bad_service=0,0,1);

SELECT @ecp_table_count AS table_count,@ecp_engine_count AS innodb_table_count,
  @ecp_required_columns AS required_column_count,@ecp_unique_count AS unique_contract_count,
  @ecp_failures AS verification_failure_count;
SET @ecp_finish_sql := IF(
  @ecp_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_ENTITLEMENT_COMPLETION_POSTCHECK_FAILED'
);
PREPARE ecp_finish_stmt FROM @ecp_finish_sql;
EXECUTE ecp_finish_stmt;
DEALLOCATE PREPARE ecp_finish_stmt;
