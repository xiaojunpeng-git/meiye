-- upgrade_key: 20260729-010-cashier-v3-checkout-resource-plan
SET NAMES utf8mb4;
SET @checkout_plan_db := DATABASE();
SET @checkout_plan_failures := 0;

SELECT COUNT(*) INTO @checkout_plan_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_plan_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_resource_plan',
    'eb_cashier_v3_checkout_resource_plan_row'
  );

SELECT COUNT(*) INTO @checkout_plan_engine_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_plan_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_resource_plan',
    'eb_cashier_v3_checkout_resource_plan_row'
  )
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @checkout_plan_header_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_plan_db
  AND TABLE_NAME='eb_cashier_v3_checkout_resource_plan';

SELECT COUNT(*) INTO @checkout_plan_row_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_plan_db
  AND TABLE_NAME='eb_cashier_v3_checkout_resource_plan_row';

SELECT COUNT(*) INTO @checkout_plan_unique_count
FROM (
  SELECT TABLE_NAME,INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@checkout_plan_db
    AND TABLE_NAME IN (
      'eb_cashier_v3_checkout_resource_plan',
      'eb_cashier_v3_checkout_resource_plan_row'
    )
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING non_unique=0 AND CONCAT(TABLE_NAME,'|',INDEX_NAME,'|',index_columns) IN (
    'eb_cashier_v3_checkout_resource_plan|uk_request_bound_version|request_id,bound_request_version',
    'eb_cashier_v3_checkout_resource_plan_row|uk_plan_physical_resource|plan_id,resource_kind,resource_id',
    'eb_cashier_v3_checkout_resource_plan_row|uk_request_version_resource|request_id,bound_request_version,resource_kind,resource_id'
  )
) checkout_plan_unique_indexes;

SELECT COUNT(*) INTO @checkout_plan_bad_headers
FROM eb_cashier_v3_checkout_resource_plan plan_header
LEFT JOIN eb_cashier_v3_checkout_request request_row
  ON request_row.request_id=plan_header.request_id
WHERE request_row.id IS NULL
  OR plan_header.request_id NOT REGEXP '^CKR-[0-9a-f]{40}$'
  OR plan_header.tenant_id='' OR plan_header.store_id=0
  OR plan_header.tenant_id<>request_row.tenant_id
  OR plan_header.store_id<>request_row.store_id
  OR plan_header.bound_request_version=0
  OR plan_header.bound_request_version>request_row.request_version
  OR plan_header.contract_version<>'cashier-v3-checkout-resource-plan-v1'
  OR plan_header.plan_status NOT IN ('active','invalidated','superseded','consumed')
  OR plan_header.resource_count=0 OR plan_header.resource_count>10000
  OR plan_header.role_count=0 OR plan_header.role_count>10000
  OR plan_header.resource_plan_fingerprint NOT REGEXP '^[a-f0-9]{64}$'
  OR plan_header.prepared_at=0 OR plan_header.created_at=0 OR plan_header.updated_at=0
  OR (plan_header.plan_status='active' AND (
      plan_header.bound_request_version<>request_row.request_version
      OR request_row.request_status<>'ready_for_submit'
  ));

SELECT COUNT(*) INTO @checkout_plan_bad_rows
FROM eb_cashier_v3_checkout_resource_plan_row plan_row
LEFT JOIN eb_cashier_v3_checkout_resource_plan plan_header
  ON plan_header.id=plan_row.plan_id
WHERE plan_header.id IS NULL
  OR plan_row.request_id<>plan_header.request_id
  OR plan_row.tenant_id<>plan_header.tenant_id
  OR plan_row.store_id<>plan_header.store_id
  OR plan_row.bound_request_version<>plan_header.bound_request_version
  OR plan_row.resource_kind='' OR plan_row.resource_kind NOT REGEXP '^[A-Za-z0-9_.:-]+$'
  OR plan_row.resource_id='' OR plan_row.resource_id NOT REGEXP '^[A-Za-z0-9_.:-]+$'
  OR plan_row.scope_type NOT IN ('tenant','store') OR plan_row.scope_id=''
  OR (plan_row.scope_type='tenant' AND plan_row.scope_id<>plan_row.tenant_id)
  OR (plan_row.scope_type='store' AND plan_row.scope_id<>CAST(plan_row.store_id AS CHAR))
  OR plan_row.lock_order=0 OR plan_row.expected_version=0
  OR plan_row.roles_json='' OR plan_row.role_count=0 OR plan_row.role_count>10000
  OR plan_row.access_mode NOT IN ('read','mutate')
  OR plan_row.provider_contract_version=''
  OR plan_row.authority_fingerprint NOT REGEXP '^[a-f0-9]{64}$'
  OR plan_row.row_fingerprint NOT REGEXP '^[a-f0-9]{64}$'
  OR plan_row.created_at=0;

SELECT COUNT(*) INTO @checkout_plan_count_drift
FROM eb_cashier_v3_checkout_resource_plan plan_header
LEFT JOIN (
  SELECT plan_id,COUNT(*) AS row_count,SUM(role_count) AS role_count
  FROM eb_cashier_v3_checkout_resource_plan_row
  GROUP BY plan_id
) plan_counts ON plan_counts.plan_id=plan_header.id
WHERE COALESCE(plan_counts.row_count,0)<>plan_header.resource_count
   OR COALESCE(plan_counts.role_count,0)<>plan_header.role_count;

SET @checkout_plan_failures := @checkout_plan_failures
  + IF(@checkout_plan_table_count=2,0,1)
  + IF(@checkout_plan_engine_count=2,0,1)
  + IF(@checkout_plan_header_columns=14,0,1)
  + IF(@checkout_plan_row_columns=19,0,1)
  + IF(@checkout_plan_unique_count=3,0,1)
  + IF(@checkout_plan_bad_headers=0,0,1)
  + IF(@checkout_plan_bad_rows=0,0,1)
  + IF(@checkout_plan_count_drift=0,0,1);

SELECT
  @checkout_plan_table_count AS table_count,
  @checkout_plan_header_columns AS header_column_count,
  @checkout_plan_row_columns AS row_column_count,
  @checkout_plan_unique_count AS unique_contract_count,
  @checkout_plan_bad_headers AS bad_header_count,
  @checkout_plan_bad_rows AS bad_row_count,
  @checkout_plan_count_drift AS count_drift,
  @checkout_plan_failures AS postcheck_failure_count;

SET @checkout_plan_finish_sql := IF(
  @checkout_plan_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''checkout resource plan postcheck failed'''
);
PREPARE checkout_plan_finish_stmt FROM @checkout_plan_finish_sql;
EXECUTE checkout_plan_finish_stmt;
DEALLOCATE PREPARE checkout_plan_finish_stmt;
