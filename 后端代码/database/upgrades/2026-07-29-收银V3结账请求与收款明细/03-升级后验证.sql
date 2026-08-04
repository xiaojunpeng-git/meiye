-- upgrade_key: 20260729-004-cashier-v3-checkout-settlement
SET NAMES utf8mb4;
SET @checkout_db := DATABASE();
SET @checkout_failures := 0;

SELECT COUNT(*) INTO @checkout_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME IN (
  'eb_cashier_v3_checkout_request',
  'eb_cashier_v3_checkout_line_draft',
  'eb_cashier_v3_checkout_payment_draft'
);

SELECT COUNT(*) INTO @checkout_engine_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME IN (
  'eb_cashier_v3_checkout_request',
  'eb_cashier_v3_checkout_line_draft',
  'eb_cashier_v3_checkout_payment_draft'
) AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @checkout_request_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME='eb_cashier_v3_checkout_request';
SELECT COUNT(*) INTO @checkout_line_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft';
SELECT COUNT(*) INTO @checkout_payment_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME='eb_cashier_v3_checkout_payment_draft';

SELECT COUNT(*) INTO @checkout_entitlement_source_detail_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_db
  AND TABLE_NAME='eb_cashier_v3_checkout_line_draft'
  AND COLUMN_NAME='entitlement_source_detail_id'
  AND COLUMN_TYPE='bigint(20) unsigned'
  AND IS_NULLABLE='NO'
  AND COLUMN_DEFAULT='0';

SELECT COUNT(*) INTO @checkout_money_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_db AND COLUMN_TYPE='bigint(20) unsigned'
  AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
    'eb_cashier_v3_checkout_request.sales_amount_cents',
    'eb_cashier_v3_checkout_request.receivable_amount_cents',
    'eb_cashier_v3_checkout_request.selected_payment_amount_cents',
    'eb_cashier_v3_checkout_request.balance_deduction_amount_cents',
    'eb_cashier_v3_checkout_request.debt_amount_cents',
    'eb_cashier_v3_checkout_request.cash_performance_amount_cents',
    'eb_cashier_v3_checkout_request.entitlement_actual_amount_cents',
    'eb_cashier_v3_checkout_line_draft.original_amount_cents',
    'eb_cashier_v3_checkout_line_draft.discount_amount_cents',
    'eb_cashier_v3_checkout_line_draft.sale_amount_cents',
    'eb_cashier_v3_checkout_line_draft.entitlement_actual_amount_cents',
    'eb_cashier_v3_checkout_payment_draft.amount_cents'
  );

SELECT COUNT(*) INTO @checkout_fingerprint_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_db AND COLUMN_TYPE='char(64)'
  AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin'
  AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
    'eb_cashier_v3_checkout_request.authority_fingerprint',
    'eb_cashier_v3_checkout_request.aggregate_fingerprint',
    'eb_cashier_v3_checkout_request.last_operation_fingerprint',
    'eb_cashier_v3_checkout_line_draft.line_fingerprint',
    'eb_cashier_v3_checkout_payment_draft.payment_fingerprint'
  );

SELECT COUNT(*) INTO @checkout_unique_contract_count
FROM (
  SELECT TABLE_NAME,INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_request',
    'eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_checkout_payment_draft'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING non_unique=0 AND CONCAT(TABLE_NAME,'|',INDEX_NAME,'|',index_columns) IN (
    'eb_cashier_v3_checkout_request|uk_request_id|request_id',
    'eb_cashier_v3_checkout_request|uk_tenant_creation_idem|tenant_id,creation_idempotency_key',
    'eb_cashier_v3_checkout_line_draft|uk_line_id|line_id',
    'eb_cashier_v3_checkout_line_draft|uk_request_authority|request_id,line_role,authority_key',
    'eb_cashier_v3_checkout_payment_draft|uk_payment_draft_id|payment_draft_id',
    'eb_cashier_v3_checkout_payment_draft|uk_request_method|request_id,payment_method',
    'eb_cashier_v3_checkout_payment_draft|uk_request_payment_authority|request_id,payment_authority_key'
  )
) checkout_unique_indexes;

SET @checkout_failures := @checkout_failures
  + IF(@checkout_table_count=3,0,1)
  + IF(@checkout_engine_count=3,0,1)
  + IF(@checkout_request_column_count=45,0,1)
  + IF(@checkout_line_column_count=31,0,1)
  + IF(@checkout_payment_column_count=26,0,1)
  + IF(@checkout_entitlement_source_detail_column_count=1,0,1)
  + IF(@checkout_money_column_count=12,0,1)
  + IF(@checkout_fingerprint_column_count=5,0,1)
  + IF(@checkout_unique_contract_count=7,0,1);

SELECT COUNT(*) INTO @checkout_bad_request
FROM eb_cashier_v3_checkout_request
WHERE request_id NOT REGEXP '^CKR-[0-9a-f]{40}$'
  OR tenant_id='' OR organization_id='' OR organization_path=''
  OR workspace_id='' OR state_context_id='' OR store_id=0 OR operator_id=0
  OR request_version=0 OR authority_snapshot_version=0
  OR request_status NOT IN ('editing','ready_for_submit','submitting','succeeded','failed','cancelled')
  OR composition NOT IN ('sale_only','entitlement_only','mixed')
  OR business_date='0000-00-00' OR business_timezone<>'Asia/Shanghai'
  OR operation_occurred_at=0 OR recorded_at<operation_occurred_at
  OR source_document_type='' OR source_document_id='' OR source_document_no=''
  OR sales_amount_cents<>receivable_amount_cents
  OR cash_performance_amount_cents<>selected_payment_amount_cents
  OR (request_status='ready_for_submit'
      AND selected_payment_amount_cents+balance_deduction_amount_cents+debt_amount_cents<>receivable_amount_cents)
  OR (composition='entitlement_only'
      AND (sales_amount_cents<>0 OR receivable_amount_cents<>0
        OR selected_payment_amount_cents<>0 OR balance_deduction_amount_cents<>0 OR debt_amount_cents<>0))
  OR (balance_deduction_amount_cents=0
      AND (balance_authority_key<>'' OR balance_account_id<>'' OR balance_account_version<>0))
  OR (balance_deduction_amount_cents>0
      AND (member_id=0 OR balance_authority_key='' OR balance_account_id='' OR balance_account_version=0))
  OR (debt_amount_cents=0 AND (debt_authority_key<>'' OR debt_policy_version<>0))
  OR (debt_amount_cents>0 AND (member_id=0 OR debt_authority_key='' OR debt_policy_version=0))
  OR authority_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
  OR aggregate_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
  OR last_operation_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
  OR creation_idempotency_key='' OR last_idempotency_key=''
  OR last_operation NOT IN ('save_draft','prepare_submission');

SELECT COUNT(*) INTO @checkout_bad_line
FROM eb_cashier_v3_checkout_line_draft
WHERE line_id NOT REGEXP '^CKL-[0-9a-f]{40}$'
  OR request_id='' OR draft_version=0 OR draft_status<>'draft'
  OR tenant_id='' OR store_id=0 OR authority_key=''
  OR line_role NOT IN ('sale','entitlement_service')
  OR source_kind='' OR source_type='' OR source_id=0 OR source_version=0 OR quantity=0
  OR source_name_snapshot='' OR line_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
  OR (line_role='sale' AND (source_type NOT IN ('product','card','project')
      OR entitlement_source_detail_id<>0
      OR original_amount_cents<>discount_amount_cents+sale_amount_cents
      OR entitlement_actual_amount_cents<>0
      OR (source_type='project' AND (project_id<>source_id OR project_version<>source_version
        OR project_name_snapshot<>source_name_snapshot))
      OR (source_type<>'project' AND (project_id<>0 OR project_version<>0 OR project_name_snapshot<>''))))
  OR (line_role='entitlement_service' AND (member_id=0
      OR entitlement_source_detail_id=0 OR project_id=0
      OR project_version=0 OR project_name_snapshot=''
      OR source_kind NOT IN ('count_card','time_card','custom_card','gift')
      OR original_amount_cents<>0 OR discount_amount_cents<>0 OR sale_amount_cents<>0));

SELECT COUNT(*) INTO @checkout_bad_payment
FROM eb_cashier_v3_checkout_payment_draft
WHERE payment_draft_id NOT REGEXP '^CKP-[0-9a-f]{40}$'
  OR request_id='' OR draft_version=0 OR tenant_id='' OR store_id=0 OR operator_id=0
  OR payment_authority_key='' OR amount_cents=0
  OR payment_method NOT IN (
    'unionpay','wechat','alipay','dianping_voucher','douyin_voucher',
    'partner_collection','other_collection'
  )
  OR business_date='0000-00-00' OR business_timezone<>'Asia/Shanghai'
  OR operation_occurred_at=0 OR recorded_at<operation_occurred_at
  OR operator_name_snapshot='' OR source_document_type=''
  OR source_document_id='' OR source_document_no=''
  OR payment_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
  OR draft_status<>'draft';

SET @checkout_failures := @checkout_failures
  + IF(@checkout_bad_request=0,0,1)
  + IF(@checkout_bad_line=0,0,1)
  + IF(@checkout_bad_payment=0,0,1);

SELECT 'CHECKOUT_REQUEST_WORKSPACE_STATUS_EXPLAIN' AS explain_marker;
EXPLAIN SELECT request_id,request_version,request_status
FROM eb_cashier_v3_checkout_request
WHERE tenant_id='0' AND workspace_id='workspace-1' AND request_status='editing'
ORDER BY update_time DESC,id DESC LIMIT 20;

SELECT 'CHECKOUT_LINE_REQUEST_EXPLAIN' AS explain_marker;
EXPLAIN SELECT line_id,line_role,sort_no
FROM eb_cashier_v3_checkout_line_draft
WHERE request_id='CKR-0000000000000000000000000000000000000000'
  AND line_role='sale'
ORDER BY sort_no ASC,id ASC;

SELECT 'CHECKOUT_PAYMENT_SCOPE_EXPLAIN' AS explain_marker;
EXPLAIN SELECT payment_draft_id,payment_method,amount_cents
FROM eb_cashier_v3_checkout_payment_draft
WHERE tenant_id='0' AND store_id=1 AND payment_method='wechat'
  AND business_date='2026-07-29'
ORDER BY id ASC;

SELECT
  @checkout_table_count AS table_count,
  @checkout_engine_count AS innodb_table_count,
  @checkout_request_column_count AS request_column_count,
  @checkout_line_column_count AS line_column_count,
  @checkout_payment_column_count AS payment_column_count,
  @checkout_entitlement_source_detail_column_count AS entitlement_source_detail_column_count,
  @checkout_money_column_count AS money_column_count,
  @checkout_fingerprint_column_count AS fingerprint_column_count,
  @checkout_unique_contract_count AS unique_contract_count,
  @checkout_bad_request AS bad_request_count,
  @checkout_bad_line AS bad_line_count,
  @checkout_bad_payment AS bad_payment_count,
  @checkout_failures AS verification_failure_count;

SET @checkout_finish_sql := IF(
  @checkout_failures=0,
  'SELECT ''POSTCHECK_OK'' AS verify_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''cashier v3 checkout settlement postcheck failed'''
);
PREPARE checkout_finish_stmt FROM @checkout_finish_sql;
EXECUTE checkout_finish_stmt;
DEALLOCATE PREPARE checkout_finish_stmt;
