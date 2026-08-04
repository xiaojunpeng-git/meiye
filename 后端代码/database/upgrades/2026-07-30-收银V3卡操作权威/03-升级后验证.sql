-- upgrade_key: 20260730-021-cashier-v3-card-operation-authority-v1
-- Read-only exact schema and invariant verification. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @cardop_db := DATABASE();
SET @cardop_failures := 0;

SELECT COUNT(*) INTO @cardop_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cardop_db AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci'
  AND TABLE_NAME IN (
    'eb_cashier_v3_card_state',
    'eb_cashier_v3_card_operation',
    'eb_cashier_v3_card_operation_line'
  );
SELECT COUNT(*) INTO @cardop_state_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cardop_db AND TABLE_NAME='eb_cashier_v3_card_state';
SELECT COUNT(*) INTO @cardop_operation_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cardop_db AND TABLE_NAME='eb_cashier_v3_card_operation';
SELECT COUNT(*) INTO @cardop_line_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cardop_db AND TABLE_NAME='eb_cashier_v3_card_operation_line';

SELECT COUNT(*) INTO @cardop_critical_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cardop_db AND (
  (TABLE_NAME='eb_cashier_v3_card_state' AND (
    (COLUMN_NAME='tenant_id' AND COLUMN_TYPE='varchar(32)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='card_holder_id' AND COLUMN_TYPE='bigint(20) unsigned')
    OR (COLUMN_NAME='current_member_id' AND COLUMN_TYPE='bigint(20) unsigned')
    OR (COLUMN_NAME='card_status' AND COLUMN_TYPE='varchar(16)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='current_version' AND COLUMN_TYPE='bigint(20) unsigned' AND COLUMN_DEFAULT='1')
  )) OR (TABLE_NAME='eb_cashier_v3_card_operation' AND (
    (COLUMN_NAME='operation_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='tenant_id' AND COLUMN_TYPE='varchar(32)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='source_card_holder_id' AND COLUMN_TYPE='bigint(20) unsigned')
    OR (COLUMN_NAME='command_idempotency_key' AND COLUMN_TYPE='varchar(128)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='immutable_fingerprint' AND COLUMN_TYPE='char(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='business_date' AND DATA_TYPE='date')
    OR (COLUMN_NAME='settled_at' AND COLUMN_TYPE='bigint(20) unsigned')
  )) OR (TABLE_NAME='eb_cashier_v3_card_operation_line' AND (
    (COLUMN_NAME='operation_line_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='operation_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='tenant_id' AND COLUMN_TYPE='varchar(32)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='natural_key' AND COLUMN_TYPE='varchar(160)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='immutable_fingerprint' AND COLUMN_TYPE='char(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
  ))
);

SELECT COUNT(*) INTO @cardop_expected_indexes
FROM (
  SELECT TABLE_NAME,INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@cardop_db AND TABLE_NAME IN (
    'eb_cashier_v3_card_state',
    'eb_cashier_v3_card_operation',
    'eb_cashier_v3_card_operation_line'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING (non_unique=0 AND CONCAT(TABLE_NAME,'|',INDEX_NAME,'|',index_columns) IN (
    'eb_cashier_v3_card_state|PRIMARY|id',
    'eb_cashier_v3_card_state|uk_tenant_holder|tenant_id,card_holder_id',
    'eb_cashier_v3_card_operation|PRIMARY|id',
    'eb_cashier_v3_card_operation|uk_operation_id|operation_id',
    'eb_cashier_v3_card_operation|uk_tenant_operation_no|tenant_id,operation_no',
    'eb_cashier_v3_card_operation|uk_tenant_command|tenant_id,command_idempotency_key',
    'eb_cashier_v3_card_operation|uk_tenant_natural|tenant_id,natural_key',
    'eb_cashier_v3_card_operation_line|PRIMARY|id',
    'eb_cashier_v3_card_operation_line|uk_operation_line_id|operation_line_id',
    'eb_cashier_v3_card_operation_line|uk_tenant_natural|tenant_id,natural_key'
  )) OR (non_unique=1 AND CONCAT(TABLE_NAME,'|',INDEX_NAME,'|',index_columns) IN (
    'eb_cashier_v3_card_state|idx_tenant_current_member|tenant_id,current_member_id,card_status,id',
    'eb_cashier_v3_card_state|idx_tenant_origin_order|tenant_id,origin_order_id,id',
    'eb_cashier_v3_card_operation|idx_scope_card_time|tenant_id,store_id,source_card_holder_id,occurred_at,id',
    'eb_cashier_v3_card_operation|idx_scope_member_time|tenant_id,store_id,member_id_after,occurred_at,id',
    'eb_cashier_v3_card_operation|idx_checkout_request|tenant_id,checkout_request_id,id',
    'eb_cashier_v3_card_operation_line|idx_operation_line|tenant_id,operation_id,line_no,id',
    'eb_cashier_v3_card_operation_line|idx_source_detail|tenant_id,source_detail_id,id'
  ))
) cardop_indexes;

SELECT COUNT(*) INTO @cardop_invalid_states
FROM eb_cashier_v3_card_state
WHERE tenant_id='' OR card_holder_id=0 OR origin_order_id=0 OR origin_member_id=0
   OR current_member_id=0 OR card_status NOT IN ('enabled','disabled')
   OR current_version=0 OR updated_at<created_at;
SELECT COUNT(*) INTO @cardop_invalid_operations
FROM eb_cashier_v3_card_operation
WHERE operation_id NOT REGEXP '^COP-[0-9A-F]{40}$'
   OR operation_no NOT REGEXP '^CO[0-9]{14}[0-9A-F]{8}$'
   OR operation_type NOT IN (
     'card_upgrade','card_extension','card_transfer','card_disable','card_enable',
     'project_replacement','project_upgrade'
   )
   OR operation_status NOT IN ('succeeded','awaiting_checkout','failed','cancelled')
   OR contract_version<>'cashier-v3-card-operation-v1'
   OR tenant_id='' OR organization_id='' OR store_id=0 OR source_card_holder_id=0
   OR source_card_holder_version=0 OR origin_order_id=0 OR origin_member_id=0
   OR member_id_before=0 OR member_id_after=0 OR operator_id=0
   OR command_idempotency_key='' OR natural_key='' OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR business_date='0000-00-00' OR business_timezone<>'Asia/Shanghai'
   OR occurred_at=0 OR recorded_at<occurred_at
   OR (operation_status='succeeded' AND settled_at<occurred_at)
   OR (operation_status<>'succeeded' AND settled_at<>0);
SELECT COUNT(*) INTO @cardop_invalid_lines
FROM eb_cashier_v3_card_operation_line
WHERE operation_line_id='' OR operation_id='' OR tenant_id='' OR line_no=0
   OR line_role='' OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR quantity_after<0;
SELECT COUNT(*) INTO @cardop_orphan_lines
FROM eb_cashier_v3_card_operation_line line_row
LEFT JOIN eb_cashier_v3_card_operation operation_row
  ON operation_row.tenant_id=line_row.tenant_id
 AND operation_row.operation_id=line_row.operation_id
WHERE operation_row.id IS NULL;

SET @cardop_failures := @cardop_failures
  + IF(@cardop_tables=3,0,1)
  + IF(@cardop_state_columns=14,0,1)
  + IF(@cardop_operation_columns=48,0,1)
  + IF(@cardop_line_columns=18,0,1)
  + IF(@cardop_critical_columns=17,0,1)
  + IF(@cardop_expected_indexes=17,0,1)
  + IF(@cardop_invalid_states=0,0,1)
  + IF(@cardop_invalid_operations=0,0,1)
  + IF(@cardop_invalid_lines=0,0,1)
  + IF(@cardop_orphan_lines=0,0,1);

SELECT
  @cardop_tables AS exact_target_table_count,
  @cardop_state_columns AS state_column_count,
  @cardop_operation_columns AS operation_column_count,
  @cardop_line_columns AS line_column_count,
  @cardop_critical_columns AS exact_critical_column_count,
  @cardop_expected_indexes AS exact_expected_index_count,
  @cardop_invalid_states AS invalid_state_count,
  @cardop_invalid_operations AS invalid_operation_count,
  @cardop_invalid_lines AS invalid_line_count,
  @cardop_orphan_lines AS orphan_line_count,
  @cardop_failures AS postcheck_failure_count;

SET @cardop_finish_sql := IF(
  @cardop_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_CARD_OPERATION_POSTCHECK_FAILED'
);
PREPARE cardop_finish_stmt FROM @cardop_finish_sql;
EXECUTE cardop_finish_stmt;
DEALLOCATE PREPARE cardop_finish_stmt;
