-- upgrade_key: 20260729-006-cashier-v3-checkout-source-authority
SET NAMES utf8mb4;
SET @checkout_source_db := DATABASE();
SET @checkout_source_failures := 0;

SELECT COUNT(*) INTO @checkout_source_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_source_db
  AND TABLE_NAME='eb_cashier_v3_checkout_source_reference';

SELECT COUNT(*) INTO @checkout_source_engine_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_source_db
  AND TABLE_NAME='eb_cashier_v3_checkout_source_reference'
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @checkout_source_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_source_db
  AND TABLE_NAME='eb_cashier_v3_checkout_source_reference';

SELECT COUNT(*) INTO @checkout_source_canonical_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_source_db
  AND TABLE_NAME='eb_cashier_v3_checkout_source_reference'
  AND CONCAT(COLUMN_NAME,'|',COLUMN_TYPE,'|',IS_NULLABLE,'|',EXTRA) IN (
    'id|bigint(20) unsigned|NO|auto_increment',
    'request_id|varchar(64)|NO|',
    'tenant_id|varchar(32)|NO|',
    'store_id|bigint(20) unsigned|NO|',
    'bound_request_version|bigint(20) unsigned|NO|',
    'source_kind|varchar(32)|NO|',
    'source_id|varchar(64)|NO|',
    'source_version|bigint(20) unsigned|NO|',
    'source_role|varchar(32)|NO|',
    'source_fingerprint|char(64)|NO|',
    'add_time|int(11) unsigned|NO|',
    'update_time|int(11) unsigned|NO|'
  );

SELECT COUNT(*) INTO @checkout_source_ascii_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_source_db
  AND TABLE_NAME='eb_cashier_v3_checkout_source_reference'
  AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin'
  AND COLUMN_NAME IN (
    'request_id','tenant_id','source_kind','source_id','source_role','source_fingerprint'
  );

SELECT COUNT(*) INTO @checkout_source_unique_count
FROM (
  SELECT INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@checkout_source_db
    AND TABLE_NAME='eb_cashier_v3_checkout_source_reference'
  GROUP BY INDEX_NAME
  HAVING non_unique=0
    AND INDEX_NAME='uk_request_source'
    AND index_columns='request_id,source_kind,source_id'
) checkout_source_unique_indexes;

SELECT COUNT(*) INTO @checkout_source_required_index_count
FROM (
  SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@checkout_source_db
    AND TABLE_NAME='eb_cashier_v3_checkout_source_reference'
  GROUP BY INDEX_NAME
  HAVING CONCAT(INDEX_NAME,'|',index_columns) IN (
    'idx_scope_request|tenant_id,store_id,request_id,id',
    'idx_source_reverse|tenant_id,store_id,source_kind,source_id,request_id',
    'idx_request_role|request_id,source_role,id'
  )
) checkout_source_required_indexes;

SELECT COUNT(*) INTO @checkout_source_bad_rows
FROM eb_cashier_v3_checkout_source_reference source_ref
LEFT JOIN eb_cashier_v3_checkout_request request_row
  ON request_row.request_id=source_ref.request_id
WHERE request_row.id IS NULL
  OR source_ref.request_id NOT REGEXP '^CKR-[0-9a-f]{40}$'
  OR source_ref.tenant_id='' OR source_ref.store_id=0
  OR source_ref.tenant_id<>request_row.tenant_id
  OR source_ref.store_id<>request_row.store_id
  OR source_ref.bound_request_version=0
  OR source_ref.bound_request_version>request_row.request_version
  OR source_ref.source_kind NOT IN ('service_order','hang_order','reservation','room')
  OR source_ref.source_id=''
  OR source_ref.source_id NOT REGEXP '^[A-Za-z0-9_.:-]+$'
  OR source_ref.source_version=0
  OR source_ref.source_role=''
  OR source_ref.source_role NOT REGEXP '^[A-Za-z0-9_.:-]+$'
  OR source_ref.source_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
  OR source_ref.add_time=0 OR source_ref.update_time=0;

SELECT COUNT(*) INTO @checkout_source_mixed_binding_sets
FROM (
  SELECT request_id
  FROM eb_cashier_v3_checkout_source_reference
  GROUP BY request_id
  HAVING MIN(bound_request_version)<>MAX(bound_request_version)
) checkout_source_mixed_bindings;

SET @checkout_source_failures := @checkout_source_failures
  + IF(@checkout_source_table_count=1,0,1)
  + IF(@checkout_source_engine_count=1,0,1)
  + IF(@checkout_source_column_count=12,0,1)
  + IF(@checkout_source_canonical_column_count=12,0,1)
  + IF(@checkout_source_ascii_column_count=6,0,1)
  + IF(@checkout_source_unique_count=1,0,1)
  + IF(@checkout_source_required_index_count=3,0,1)
  + IF(@checkout_source_bad_rows=0,0,1)
  + IF(@checkout_source_mixed_binding_sets=0,0,1);

SELECT
  @checkout_source_table_count AS table_count,
  @checkout_source_column_count AS column_count,
  @checkout_source_canonical_column_count AS canonical_column_count,
  @checkout_source_unique_count AS unique_contract_count,
  @checkout_source_required_index_count AS required_index_count,
  @checkout_source_bad_rows AS bad_row_count,
  @checkout_source_mixed_binding_sets AS mixed_binding_set_count,
  @checkout_source_failures AS postcheck_failure_count;

SET @checkout_source_finish_sql := IF(
  @checkout_source_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''checkout source authority postcheck failed'''
);
PREPARE checkout_source_finish_stmt FROM @checkout_source_finish_sql;
EXECUTE checkout_source_finish_stmt;
DEALLOCATE PREPARE checkout_source_finish_stmt;
