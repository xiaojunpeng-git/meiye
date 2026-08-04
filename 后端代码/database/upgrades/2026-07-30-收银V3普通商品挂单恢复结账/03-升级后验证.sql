-- upgrade_key: 20260730-022-cashier-v3-sale-hang-resume-checkout-v1
-- Read-only schema and new-contract invariant verification. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @hr_db := DATABASE();
SET @hr_failures := 0;

SELECT COUNT(*) INTO @hr_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@hr_db AND ENGINE='InnoDB' AND TABLE_NAME IN (
  'eb_cashier_v3_hang_order',
  'eb_cashier_v3_hang_order_line',
  'eb_cashier_v3_workspace_draft'
);

SELECT COUNT(*) INTO @hr_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@hr_db AND (
  (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_contract_version' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_workspace_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_state_context_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_command_idempotency_key' AND COLUMN_TYPE='varchar(128)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resumed_at' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='checkout_request_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='sales_order_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='settled_at' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
  OR (TABLE_NAME='eb_cashier_v3_hang_order_line' AND COLUMN_NAME='workspace_snapshot_json' AND DATA_TYPE='longtext' AND IS_NULLABLE='YES')
  OR (TABLE_NAME='eb_cashier_v3_workspace_draft' AND COLUMN_NAME='resumed_hang_order_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
);

SELECT COUNT(*) INTO @hr_indexes
FROM (
  SELECT TABLE_NAME, INDEX_NAME, MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@hr_db AND (
    (TABLE_NAME='eb_cashier_v3_hang_order' AND INDEX_NAME IN ('idx_resume_workspace_status','idx_checkout_request'))
    OR (TABLE_NAME='eb_cashier_v3_workspace_draft' AND INDEX_NAME='idx_resumed_hang_order')
  )
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING (TABLE_NAME='eb_cashier_v3_hang_order' AND INDEX_NAME='idx_resume_workspace_status' AND non_unique=1 AND index_columns='tenant_id,store_id,resume_workspace_id,hang_status,id')
      OR (TABLE_NAME='eb_cashier_v3_hang_order' AND INDEX_NAME='idx_checkout_request' AND non_unique=1 AND index_columns='tenant_id,store_id,checkout_request_id,id')
      OR (TABLE_NAME='eb_cashier_v3_workspace_draft' AND INDEX_NAME='idx_resumed_hang_order' AND non_unique=1 AND index_columns='resumed_hang_order_id,id')
) hr_indexes;

SELECT COUNT(*) INTO @hr_invalid_headers
FROM eb_cashier_v3_hang_order
WHERE resume_contract_version<>'' AND (
  resume_contract_version<>'cashier-v3-hang-resume-sale-only-v1'
  OR hang_mode<>'normal'
  OR hang_status NOT IN ('pending_checkout','resumed_checkout','settled')
  OR line_count=0 OR entitlement_actual_amount_cents<>0
  OR (hang_status='pending_checkout' AND (resume_workspace_id<>'' OR resume_state_context_id<>'' OR resume_command_idempotency_key<>'' OR resumed_at<>0 OR checkout_request_id<>'' OR sales_order_id<>'' OR settled_at<>0))
  OR (hang_status='resumed_checkout' AND (resume_workspace_id='' OR resume_state_context_id='' OR resume_command_idempotency_key='' OR resumed_at=0 OR checkout_request_id<>'' OR sales_order_id<>'' OR settled_at<>0))
  OR (hang_status='settled' AND (resume_workspace_id='' OR resume_state_context_id='' OR resume_command_idempotency_key='' OR resumed_at=0 OR checkout_request_id='' OR sales_order_id='' OR settled_at<resumed_at))
);

SELECT COUNT(*) INTO @hr_invalid_lines
FROM eb_cashier_v3_hang_order_line l
JOIN eb_cashier_v3_hang_order h
  ON h.tenant_id=l.tenant_id AND h.hang_order_id=l.hang_order_id
WHERE h.resume_contract_version='cashier-v3-hang-resume-sale-only-v1' AND (
  l.line_role<>'sale' OR l.workspace_snapshot_json IS NULL OR l.workspace_snapshot_json=''
  OR l.line_status NOT IN ('held','resumed','settled')
  OR (h.hang_status='pending_checkout' AND (l.line_status<>'held' OR l.line_version<>1))
  OR (h.hang_status='resumed_checkout' AND (l.line_status<>'resumed' OR l.line_version<>2))
  OR (h.hang_status='settled' AND (l.line_status<>'settled' OR l.line_version<>3))
);

SELECT COUNT(*) INTO @hr_invalid_workspace_bindings
FROM eb_cashier_v3_workspace_draft d
LEFT JOIN eb_cashier_v3_hang_order h
  ON h.hang_order_id=d.resumed_hang_order_id AND h.store_id=d.store_id
WHERE d.resumed_hang_order_id<>'' AND (
  h.id IS NULL OR h.hang_status<>'resumed_checkout'
  OR h.resume_workspace_id<>d.workspace_id OR h.resume_state_context_id<>d.state_context_id
);

SET @hr_failures := @hr_failures
  + IF(@hr_tables=3,0,1)
  + IF(@hr_columns=10,0,1)
  + IF(@hr_indexes=3,0,1)
  + IF(@hr_invalid_headers=0,0,1)
  + IF(@hr_invalid_lines=0,0,1)
  + IF(@hr_invalid_workspace_bindings=0,0,1);

SELECT IF(@hr_failures=0,'POSTCHECK_OK','POSTCHECK_FAILED') AS postcheck_result,
  @hr_failures AS failure_count,
  @hr_columns AS exact_column_count,
  @hr_indexes AS exact_index_count,
  @hr_invalid_headers AS invalid_header_count,
  @hr_invalid_lines AS invalid_line_count,
  @hr_invalid_workspace_bindings AS invalid_workspace_binding_count;

SET @hr_abort_sql := IF(
  @hr_failures=0,
  'SELECT ''POSTCHECK_CONTINUE'' AS gate',
  'SELECT * FROM STOP_CASHIER_V3_HANG_RESUME_POSTCHECK_FAILED'
);
PREPARE hr_postcheck_stmt FROM @hr_abort_sql;
EXECUTE hr_postcheck_stmt;
DEALLOCATE PREPARE hr_postcheck_stmt;
