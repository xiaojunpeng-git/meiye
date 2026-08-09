-- upgrade_key: 20260729-005-c3-service-order-authority
SET NAMES utf8mb4;
SET @c3so_db := DATABASE();
SET @c3so_failures := 0;

SELECT COUNT(*) INTO @c3so_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME IN (
  'eb_cashier_v3_service_order','eb_cashier_v3_service_order_line',
  'eb_cashier_v3_service_order_entitlement_guard','eb_cashier_v3_service_order_operation'
);
SELECT COUNT(*) INTO @c3so_engine_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME IN (
  'eb_cashier_v3_service_order','eb_cashier_v3_service_order_line',
  'eb_cashier_v3_service_order_entitlement_guard','eb_cashier_v3_service_order_operation'
) AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @c3so_required_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c3so_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_cashier_v3_service_order.id','eb_cashier_v3_service_order.service_order_no',
  'eb_cashier_v3_service_order.tenant_id','eb_cashier_v3_service_order.business_store_id',
  'eb_cashier_v3_service_order.member_id','eb_cashier_v3_service_order.participant_employee_ids_json',
  'eb_cashier_v3_service_order.status','eb_cashier_v3_service_order.version',
  'eb_cashier_v3_service_order.business_date','eb_cashier_v3_service_order.created_at',
  'eb_cashier_v3_service_order.updated_at',
  'eb_cashier_v3_service_order_line.id','eb_cashier_v3_service_order_line.tenant_id',
  'eb_cashier_v3_service_order_line.service_order_id','eb_cashier_v3_service_order_line.line_key',
  'eb_cashier_v3_service_order_line.entitlement_source_detail_id',
  'eb_cashier_v3_service_order_line.occupied_times','eb_cashier_v3_service_order_line.status',
  'eb_cashier_v3_service_order_line.version',
  'eb_cashier_v3_service_order_entitlement_guard.id',
  'eb_cashier_v3_service_order_entitlement_guard.tenant_id',
  'eb_cashier_v3_service_order_entitlement_guard.entitlement_source_detail_id',
  'eb_cashier_v3_service_order_entitlement_guard.current_version',
  'eb_cashier_v3_service_order_operation.id','eb_cashier_v3_service_order_operation.tenant_id',
  'eb_cashier_v3_service_order_operation.command_idempotency_key',
  'eb_cashier_v3_service_order_operation.request_fingerprint',
  'eb_cashier_v3_service_order_operation.operation_type',
  'eb_cashier_v3_service_order_operation.service_order_id',
  'eb_cashier_v3_service_order_operation.service_order_version_before',
  'eb_cashier_v3_service_order_operation.service_order_version_after',
  'eb_cashier_v3_service_order_operation.recorded_at'
);

SELECT COUNT(*) INTO @c3so_unique_count
FROM (
  SELECT TABLE_NAME,INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME IN (
    'eb_cashier_v3_service_order','eb_cashier_v3_service_order_line',
    'eb_cashier_v3_service_order_entitlement_guard','eb_cashier_v3_service_order_operation'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING non_unique=0 AND CONCAT(TABLE_NAME,'|',INDEX_NAME,'|',index_columns) IN (
    'eb_cashier_v3_service_order|uk_tenant_service_order_no|tenant_id,service_order_no',
    'eb_cashier_v3_service_order_line|uk_tenant_order_line|tenant_id,service_order_id,line_key',
    'eb_cashier_v3_service_order_entitlement_guard|uk_tenant_entitlement_detail|tenant_id,entitlement_source_detail_id',
    'eb_cashier_v3_service_order_operation|uk_tenant_operation_key|tenant_id,operation_key',
    'eb_cashier_v3_service_order_operation|uk_tenant_command_idem|tenant_id,command_idempotency_key'
  )
) c3so_unique_indexes;

SELECT COUNT(*) INTO @c3so_lock_index_count
FROM (
  SELECT INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c3so_db AND TABLE_NAME='eb_cashier_v3_service_order_line'
  GROUP BY INDEX_NAME
  HAVING non_unique=1 AND CONCAT(INDEX_NAME,'|',index_columns) IN (
    'idx_tenant_detail_order|tenant_id,entitlement_source_detail_id,service_order_id,id,status',
    'idx_tenant_order_line|tenant_id,service_order_id,id,status'
  )
) c3so_lock_indexes;

SELECT COUNT(*) INTO @c3so_bad_order FROM eb_cashier_v3_service_order
WHERE service_order_no='' OR tenant_id='' OR business_store_id=0
  OR source_type NOT IN ('direct','reservation','hang','checkout')
  OR participant_employee_ids_json='' OR status NOT IN (
    'OPEN','IN_SERVICE','PENDING_CHECKOUT','COMPLETED','CANCELLED','VOIDED'
  ) OR version=0 OR business_date='0000-00-00' OR occurred_at=0 OR recorded_at=0
  OR created_by_staff_id=0 OR created_by_employee_id=0 OR created_at=0 OR updated_at=0;
SELECT COUNT(*) INTO @c3so_bad_line FROM eb_cashier_v3_service_order_line
WHERE tenant_id='' OR service_order_id=0 OR line_key='' OR entitlement_source_detail_id=0
  OR project_id=0 OR service_target NOT IN ('SELF','FRIEND') OR is_experience NOT IN (0,1)
  OR status NOT IN ('ACTIVE','RELEASED') OR version=0
  OR (status='ACTIVE' AND occupied_times=0) OR (status='RELEASED' AND occupied_times<>0)
  OR created_at=0 OR updated_at=0;
SELECT COUNT(*) INTO @c3so_bad_line_owner
FROM eb_cashier_v3_service_order_line line_row
LEFT JOIN eb_cashier_v3_service_order order_row
  ON order_row.id=line_row.service_order_id AND order_row.tenant_id=line_row.tenant_id
WHERE order_row.id IS NULL OR (line_row.status='ACTIVE' AND order_row.member_id=0);
SELECT COUNT(*) INTO @c3so_bad_guard FROM eb_cashier_v3_service_order_entitlement_guard
WHERE tenant_id='' OR entitlement_source_detail_id=0 OR current_version=0 OR created_at=0 OR updated_at=0;
SELECT COUNT(*) INTO @c3so_bad_operation FROM eb_cashier_v3_service_order_operation
WHERE operation_key='' OR command_idempotency_key='' OR tenant_id='' OR business_store_id=0
  OR request_fingerprint NOT REGEXP '^[a-f0-9]{64}$' OR operation_type=''
  OR service_order_id=0 OR service_order_version_after=0
  OR actor_staff_id=0 OR actor_employee_id=0 OR result_json='' OR occurred_at=0 OR recorded_at=0;

SET @c3so_failures := @c3so_failures
  + IF(@c3so_table_count=4,0,1)
  + IF(@c3so_engine_count=4,0,1)
  + IF(@c3so_required_columns=32,0,1)
  + IF(@c3so_unique_count=5,0,1)
  + IF(@c3so_lock_index_count=2,0,1)
  + IF(@c3so_bad_order=0,0,1)
  + IF(@c3so_bad_line=0,0,1)
  + IF(@c3so_bad_line_owner=0,0,1)
  + IF(@c3so_bad_guard=0,0,1)
  + IF(@c3so_bad_operation=0,0,1);

SELECT @c3so_table_count AS table_count,@c3so_engine_count AS innodb_table_count,
  @c3so_required_columns AS required_column_count,@c3so_unique_count AS unique_contract_count,
  @c3so_lock_index_count AS lock_index_count,
  @c3so_failures AS verification_failure_count;
SET @c3so_finish_sql := IF(
  @c3so_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_C3_SERVICE_ORDER_POSTCHECK_FAILED'
);
PREPARE c3so_finish_stmt FROM @c3so_finish_sql;
EXECUTE c3so_finish_stmt;
DEALLOCATE PREPARE c3so_finish_stmt;
