-- upgrade_key: 20260729-001-customer-care-core
-- Exact post-upgrade verification. Register the upgrade key only after this succeeds.
SET NAMES utf8mb4;
SET SESSION group_concat_max_len=1048576;
SET @care_db := DATABASE();
SET @care_failures := 0;
SET @care_c1_upgrade_key_used := 0;
SET @care_employee_upgrade_key_used := 0;

SELECT @care_db AS db_name, VERSION() AS mysql_version;

SELECT COUNT(*) INTO @care_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_database_upgrade_log';
SELECT COUNT(*) INTO @care_upgrade_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key'
  AND COLUMN_TYPE='varchar(100)'
  AND IS_NULLABLE='NO';
SET @care_c1_log_sql := IF(
  @care_upgrade_log_exists=1 AND @care_upgrade_key_column=1,
  'SELECT COALESCE(SUM(upgrade_key=''20260727-001-cashier-v3-command-idem''),0),COALESCE(SUM(upgrade_key=''20260719-009-employee-org-leader''),0) INTO @care_c1_upgrade_key_used,@care_employee_upgrade_key_used FROM eb_database_upgrade_log WHERE upgrade_key IN (''20260727-001-cashier-v3-command-idem'',''20260719-009-employee-org-leader'')',
  'SET @care_c1_upgrade_key_used:=0,@care_employee_upgrade_key_used:=0'
);
PREPARE care_c1_log_stmt FROM @care_c1_log_sql;
EXECUTE care_c1_log_stmt;
DEALLOCATE PREPARE care_c1_log_stmt;

SELECT COUNT(*) INTO @care_c1_receipt_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME='eb_cashier_v3_command_receipt'
  AND ENGINE='InnoDB'
  AND TABLE_COLLATION='utf8mb4_general_ci';
SELECT COUNT(*) INTO @care_c1_receipt_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_cashier_v3_command_receipt';
SELECT COUNT(*) INTO @care_c1_receipt_ascii_contract_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME='eb_cashier_v3_command_receipt'
  AND CHARACTER_SET_NAME='ascii'
  AND COLLATION_NAME='ascii_bin'
  AND COLUMN_NAME IN (
    'idempotency_key','action','state_context_id','request_hash','contexts_hash',
    'result_code','business_no','operator_ip'
  );
SELECT COUNT(*) INTO @care_c1_receipt_payload_contract_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME='eb_cashier_v3_command_receipt'
  AND COLUMN_NAME IN ('contexts_json','result_json')
  AND COLUMN_TYPE='mediumtext'
  AND IS_NULLABLE='YES';
SELECT COUNT(*) INTO @care_c1_receipt_unique_contract_count
FROM (
  SELECT INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
         GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_cashier_v3_command_receipt'
  GROUP BY INDEX_NAME
  HAVING non_unique=0 AND index_columns='idempotency_key'
) care_c1_receipt_unique;

SELECT COUNT(*) INTO @care_employee_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME IN ('eb_employee','eb_system_store_staff')
  AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @care_employee_column_contract_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND (
    (TABLE_NAME='eb_employee' AND COLUMN_NAME IN ('id','status','is_del'))
    OR (TABLE_NAME='eb_system_store_staff' AND COLUMN_NAME IN (
      'id','store_id','employee_id','staff_name','status','is_del'
    ))
  );
SELECT COUNT(*) INTO @care_employee_id_column_contract_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND (
    (TABLE_NAME='eb_employee' AND COLUMN_NAME='id'
      AND COLUMN_TYPE='int(10) unsigned' AND IS_NULLABLE='NO')
    OR (TABLE_NAME='eb_system_store_staff' AND COLUMN_NAME='employee_id'
      AND COLUMN_TYPE='int(10) unsigned' AND IS_NULLABLE='YES')
  );
SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,'|',INDEX_NAME))
INTO @care_employee_index_contract_count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@care_db
  AND (
    (TABLE_NAME='eb_employee' AND INDEX_NAME='idx_employee_status_del')
    OR (TABLE_NAME='eb_system_store_staff' AND INDEX_NAME IN (
      'idx_staff_employee_store','idx_staff_store_employee'
    ))
  );
SET @care_invalid_active_assignment_count := -1;
SET @care_assignment_sql := IF(
  @care_employee_table_count=2 AND @care_employee_column_contract_count=9,
  'SELECT COUNT(*) INTO @care_invalid_active_assignment_count FROM eb_system_store_staff staff LEFT JOIN eb_employee employee ON employee.id=staff.employee_id WHERE staff.status=1 AND staff.is_del=0 AND (staff.employee_id IS NULL OR staff.employee_id=0 OR employee.id IS NULL OR employee.status<>1 OR employee.is_del<>0)',
  'SET @care_invalid_active_assignment_count:=-1'
);
PREPARE care_assignment_stmt FROM @care_assignment_sql;
EXECUTE care_assignment_stmt;
DEALLOCATE PREPARE care_assignment_stmt;

SELECT COUNT(*) INTO @care_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME IN (
  'eb_customer_care_task',
  'eb_customer_care_record',
  'eb_customer_care_operation'
);

SELECT COUNT(*) INTO @care_engine_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME IN (
  'eb_customer_care_task',
  'eb_customer_care_record',
  'eb_customer_care_operation'
)
  AND ENGINE='InnoDB'
  AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @care_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME IN (
  'eb_customer_care_task',
  'eb_customer_care_record',
  'eb_customer_care_operation'
);

SELECT COUNT(*) INTO @care_ascii_contract_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND CHARACTER_SET_NAME='ascii'
  AND COLLATION_NAME='ascii_bin'
  AND (
    (TABLE_NAME='eb_customer_care_task' AND COLUMN_NAME IN (
      'task_key','create_idempotency_key','create_request_fingerprint','tenant_id',
      'organization_id','organization_path','task_type','source_type','source_id',
      'status','planned_timezone'
    ))
    OR (TABLE_NAME='eb_customer_care_record' AND COLUMN_NAME IN (
      'record_key','command_idempotency_key','request_fingerprint','tenant_id',
      'organization_id','organization_path','operation_organization_id',
      'operation_organization_path','business_timezone_snapshot','record_type',
      'followup_method','result_code','related_business_type','related_business_id','status'
    ))
    OR (TABLE_NAME='eb_customer_care_operation' AND COLUMN_NAME IN (
      'operation_key','command_idempotency_key','request_fingerprint','tenant_id',
      'organization_id','organization_path','operation_organization_id',
      'operation_organization_path','operation_type','task_status_before',
      'task_status_after','record_status_before','record_status_after'
    ))
  );

SELECT COUNT(*) INTO @care_nullable_task_contract
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME='eb_customer_care_record'
  AND COLUMN_NAME='task_id'
  AND COLUMN_TYPE='bigint(20) unsigned'
  AND IS_NULLABLE='YES'
  AND COLUMN_DEFAULT IS NULL;

SELECT COUNT(*) INTO @care_nullable_next_task_contract
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME='eb_customer_care_record'
  AND COLUMN_NAME='next_task_id'
  AND COLUMN_TYPE='bigint(20) unsigned'
  AND IS_NULLABLE='YES'
  AND COLUMN_DEFAULT IS NULL;

SELECT COUNT(*), SUM(has_prefix) INTO @care_index_count,@care_prefix_index_count
FROM (
  SELECT TABLE_NAME,INDEX_NAME,
    IF(SUM(SUB_PART IS NOT NULL)=0,0,1) AS has_prefix
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME IN (
    'eb_customer_care_task',
    'eb_customer_care_record',
    'eb_customer_care_operation'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
) care_indexes;

SELECT COUNT(*) INTO @care_unique_contract_count
FROM (
  SELECT TABLE_NAME,INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME IN (
    'eb_customer_care_task',
    'eb_customer_care_record',
    'eb_customer_care_operation'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING non_unique=0 AND CONCAT(TABLE_NAME,'|',INDEX_NAME,'|',index_columns) IN (
    'eb_customer_care_task|uk_tenant_task_key|tenant_id,task_key',
    'eb_customer_care_task|uk_tenant_create_idem|tenant_id,create_idempotency_key',
    'eb_customer_care_record|uk_tenant_record_key|tenant_id,record_key',
    'eb_customer_care_record|uk_tenant_record_idem|tenant_id,command_idempotency_key',
    'eb_customer_care_record|uk_tenant_task_record|tenant_id,task_id',
    'eb_customer_care_record|uk_tenant_next_task|tenant_id,next_task_id',
    'eb_customer_care_operation|uk_tenant_operation_key|tenant_id,operation_key',
    'eb_customer_care_operation|uk_tenant_operation_idem|tenant_id,command_idempotency_key'
  )
) care_unique_indexes;

SET @care_failures := @care_failures
  + IF(@care_upgrade_log_exists=1,0,1)
  + IF(@care_upgrade_key_column=1,0,1)
  + IF(@care_c1_upgrade_key_used=1,0,1)
  + IF(@care_employee_upgrade_key_used=1,0,1)
  + IF(@care_c1_receipt_table_count=1,0,1)
  + IF(@care_c1_receipt_column_count=17,0,1)
  + IF(@care_c1_receipt_ascii_contract_count=8,0,1)
  + IF(@care_c1_receipt_payload_contract_count=2,0,1)
  + IF(@care_c1_receipt_unique_contract_count=1,0,1)
  + IF(@care_employee_table_count=2,0,1)
  + IF(@care_employee_column_contract_count=9,0,1)
  + IF(@care_employee_id_column_contract_count=2,0,1)
  + IF(@care_employee_index_contract_count=3,0,1)
  + IF(@care_invalid_active_assignment_count=0,0,1)
  + IF(@care_table_count=3,0,1)
  + IF(@care_engine_count=3,0,1)
  + IF(@care_column_count=128,0,1)
  + IF(@care_ascii_contract_count=39,0,1)
  + IF(@care_nullable_task_contract=1,0,1)
  + IF(@care_nullable_next_task_contract=1,0,1)
  + IF(@care_index_count=26,0,1)
  + IF(IFNULL(@care_prefix_index_count,0)=0,0,1)
  + IF(@care_unique_contract_count=8,0,1);

SELECT COUNT(*) INTO @care_bad_tasks
FROM eb_customer_care_task
WHERE task_key=''
  OR create_idempotency_key=''
  OR create_request_fingerprint=''
  OR tenant_id=''
  OR organization_id=''
  OR organization_path=''
  OR business_store_id=0
  OR member_id=0
  OR member_name_snapshot=''
  OR owner_staff_id=0
  OR owner_employee_id=0
  OR planned_at=0
  OR version=0
  OR status NOT IN ('UNSTARTED','IN_PROGRESS','COMPLETED','VOIDED')
  OR is_visible NOT IN (0,1)
  OR (is_visible=0 AND (status<>'UNSTARTED' OR deleted_at=0))
  OR (is_visible=1 AND deleted_at<>0)
  OR (status='UNSTARTED' AND (started_at<>0 OR completed_at<>0 OR voided_at<>0))
  OR (status='IN_PROGRESS' AND (started_at=0 OR completed_at<>0 OR voided_at<>0))
  OR (status='COMPLETED' AND (started_at=0 OR completed_at=0 OR voided_at<>0))
  OR (status='VOIDED' AND (started_at=0 OR completed_at<>0 OR voided_at=0));

SELECT COUNT(*) INTO @care_bad_records
FROM eb_customer_care_record
WHERE record_key=''
  OR command_idempotency_key=''
  OR request_fingerprint=''
  OR tenant_id=''
  OR organization_id=''
  OR organization_path=''
  OR operation_organization_id=''
  OR operation_organization_path=''
  OR business_timezone_snapshot=''
  OR business_store_id=0
  OR operation_store_id=0
  OR (task_id IS NOT NULL AND task_id=0)
  OR member_id=0
  OR (task_id IS NULL AND (owner_staff_id<>0 OR owner_employee_id<>0 OR owner_name_snapshot<>''))
  OR (task_id IS NOT NULL AND (owner_staff_id=0 OR owner_employee_id=0 OR owner_name_snapshot=''))
  OR record_type=''
  OR followup_method=''
  OR result_code=''
  OR summary=''
  OR followed_at=0
  OR follower_staff_id=0
  OR follower_employee_id=0
  OR follower_name_snapshot=''
  OR created_by_staff_id=0
  OR created_by_staff_id<>follower_staff_id
  OR ((related_business_type='' OR related_business_id='' OR related_business_label_snapshot='')
    AND NOT (related_business_type='' AND related_business_id='' AND related_business_label_snapshot=''))
  OR requires_next_followup NOT IN (0,1)
  OR (requires_next_followup=0 AND (
    next_task_id IS NOT NULL OR next_planned_at<>0
    OR next_owner_staff_id<>0 OR next_owner_employee_id<>0 OR next_owner_name_snapshot<>''
  ))
  OR (requires_next_followup=1 AND (
    next_task_id IS NULL OR next_task_id=0 OR next_planned_at=0
    OR next_owner_staff_id=0 OR next_owner_employee_id=0 OR next_owner_name_snapshot=''
  ))
  OR status NOT IN ('NORMAL','VOIDED')
  OR version=0
  OR business_date='0000-00-00'
  OR occurred_at=0
  OR settled_at=0
  OR recorded_at=0
  OR followed_at>occurred_at
  OR IF(occurred_at>=followed_at,occurred_at-followed_at,0)>158112000
  OR (status='NORMAL' AND (voided_at<>0 OR voided_by_staff_id<>0 OR void_reason<>''))
  OR (status='VOIDED' AND (voided_at=0 OR voided_by_staff_id=0 OR void_reason=''));

SELECT COUNT(*) INTO @care_bad_operations
FROM eb_customer_care_operation
WHERE operation_key=''
  OR command_idempotency_key=''
  OR request_fingerprint=''
  OR tenant_id=''
  OR organization_id=''
  OR organization_path=''
  OR operation_organization_id=''
  OR operation_organization_path=''
  OR business_store_id=0
  OR operation_store_id=0
  OR (operation_type NOT IN ('CREATE_RECORD','VOID_RECORD') AND task_id=0)
  OR (operation_type='CREATE_RECORD' AND task_id<>0)
  OR member_id=0
  OR operation_type NOT IN (
    'CREATE_TASK','CREATE_RECORD','START_TASK','COMPLETE_TASK','DELETE_TASK',
    'VOID_TASK','REASSIGN_TASK','VOID_RECORD'
  )
  OR task_status_before NOT IN ('','UNSTARTED','IN_PROGRESS','COMPLETED','VOIDED')
  OR task_status_after NOT IN ('','UNSTARTED','IN_PROGRESS','COMPLETED','VOIDED')
  OR record_status_before NOT IN ('','NORMAL','VOIDED')
  OR record_status_after NOT IN ('','NORMAL','VOIDED')
  OR (operation_type<>'COMPLETE_TASK' AND (
    next_task_id_after<>0 OR next_task_version_after<>0
  ))
  OR (operation_type='COMPLETE_TASK' AND (
    (next_task_id_after=0 AND next_task_version_after<>0)
    OR (next_task_id_after<>0 AND next_task_version_after<>1)
  ))
  OR (operation_type NOT IN ('CREATE_RECORD','VOID_RECORD') AND task_version_after=0)
  OR is_visible_before NOT IN (0,1)
  OR is_visible_after NOT IN (0,1)
  OR actor_staff_id=0
  OR actor_employee_id=0
  OR (operation_type NOT IN ('CREATE_RECORD','VOID_RECORD') AND owner_name_snapshot_after='')
  OR (operation_type='VOID_RECORD' AND task_id<>0 AND owner_name_snapshot_after='')
  OR (operation_type IN ('CREATE_RECORD','VOID_RECORD') AND task_id=0 AND (
    owner_name_snapshot_before<>'' OR owner_name_snapshot_after<>''
  ))
  OR (operation_type='CREATE_TASK' AND owner_name_snapshot_before<>'')
  OR (operation_type NOT IN ('CREATE_TASK','CREATE_RECORD','VOID_RECORD') AND owner_name_snapshot_before='')
  OR (operation_type='VOID_RECORD' AND task_id<>0 AND owner_name_snapshot_before='')
  OR occurred_at=0
  OR recorded_at=0
  OR (operation_type='CREATE_TASK' AND task_version_before<>0)
  OR (operation_type NOT IN ('CREATE_TASK','CREATE_RECORD','VOID_RECORD') AND task_version_before=0)
  OR (operation_type='CREATE_TASK' AND (
    task_status_before<>'' OR task_status_after<>'UNSTARTED'
    OR task_version_after<>1 OR is_visible_before<>0 OR is_visible_after<>1
    OR owner_staff_id_before<>0 OR owner_staff_id_after=0
  ))
  OR (operation_type='CREATE_RECORD' AND (
    task_status_before<>'' OR task_status_after<>''
    OR task_version_before<>0 OR task_version_after<>0
    OR record_id=0 OR record_status_before<>'' OR record_status_after<>'NORMAL'
    OR record_version_before<>0 OR record_version_after<>1
    OR owner_staff_id_before<>0 OR owner_staff_id_after<>0
    OR owner_employee_id_before<>0 OR owner_employee_id_after<>0
    OR is_visible_before<>0 OR is_visible_after<>0
  ))
  OR (operation_type='START_TASK' AND (
    task_status_before<>'UNSTARTED' OR task_status_after<>'IN_PROGRESS'
    OR task_version_after<>task_version_before+1
    OR owner_staff_id_before<>owner_staff_id_after
    OR is_visible_before<>1 OR is_visible_after<>1
  ))
  OR (operation_type='COMPLETE_TASK' AND (
    task_status_before<>'IN_PROGRESS' OR task_status_after<>'COMPLETED'
    OR task_version_after<>task_version_before+1
    OR record_id=0 OR record_status_before<>'' OR record_status_after<>'NORMAL'
    OR record_version_before<>0 OR record_version_after<>1
    OR owner_staff_id_before<>owner_staff_id_after
  ))
  OR (operation_type='DELETE_TASK' AND (
    task_status_before<>'UNSTARTED' OR task_status_after<>'UNSTARTED'
    OR task_version_after<>task_version_before+1
    OR is_visible_before<>1 OR is_visible_after<>0
    OR owner_staff_id_before<>owner_staff_id_after
  ))
  OR (operation_type='VOID_TASK' AND (
    task_status_before<>'IN_PROGRESS' OR task_status_after<>'VOIDED'
    OR task_version_after<>task_version_before+1
    OR owner_staff_id_before<>owner_staff_id_after
  ))
  OR (operation_type='REASSIGN_TASK' AND (
    task_status_before NOT IN ('UNSTARTED','IN_PROGRESS')
    OR task_status_after<>task_status_before
    OR task_version_after<>task_version_before+1
    OR owner_staff_id_before=0 OR owner_staff_id_after=0
    OR owner_staff_id_before=owner_staff_id_after
  ))
  OR (operation_type='VOID_RECORD' AND (
    record_id=0 OR record_status_before<>'NORMAL' OR record_status_after<>'VOIDED'
    OR record_version_before=0 OR record_version_after<>record_version_before+1
    OR (task_id=0 AND (
      task_status_before<>'' OR task_status_after<>''
      OR task_version_before<>0 OR task_version_after<>0
      OR owner_staff_id_before<>0 OR owner_staff_id_after<>0
      OR owner_employee_id_before<>0 OR owner_employee_id_after<>0
      OR is_visible_before<>0 OR is_visible_after<>0
    ))
    OR (task_id<>0 AND (
      task_status_before<>'COMPLETED' OR task_status_after<>'COMPLETED'
      OR task_version_after<>task_version_before+1
      OR owner_staff_id_before<>owner_staff_id_after
    ))
  ))
  OR (operation_type NOT IN ('CREATE_RECORD','COMPLETE_TASK','VOID_RECORD') AND (
    record_id<>0 OR record_status_before<>'' OR record_status_after<>''
    OR record_version_before<>0 OR record_version_after<>0
  ))
  OR (operation_type IN ('DELETE_TASK','VOID_TASK','REASSIGN_TASK','VOID_RECORD') AND reason='')
  OR task_status_before IN ('DELETED','REASSIGNED')
  OR task_status_after IN ('DELETED','REASSIGNED');

SET @care_failures := @care_failures
  + IF(@care_bad_tasks=0,0,1)
  + IF(@care_bad_records=0,0,1)
  + IF(@care_bad_operations=0,0,1);

SELECT 'CARE_EXPLAIN_STORE_OVERDUE' AS explain_marker;
EXPLAIN SELECT id,task_key,status,planned_at
FROM eb_customer_care_task
WHERE tenant_id='0' AND business_store_id=1
  AND status IN ('UNSTARTED','IN_PROGRESS') AND is_visible=1
  AND planned_at<UNIX_TIMESTAMP()
ORDER BY planned_at ASC,id ASC LIMIT 50;

SELECT 'CARE_EXPLAIN_OWNER_WORKLOAD' AS explain_marker;
EXPLAIN SELECT id,task_key,status,planned_at
FROM eb_customer_care_task
WHERE tenant_id='0' AND owner_staff_id=1
  AND status='UNSTARTED' AND is_visible=1
ORDER BY planned_at ASC,id ASC LIMIT 50;

SELECT 'CARE_EXPLAIN_ORGANIZATION_PLAN' AS explain_marker;
EXPLAIN SELECT id,task_key,planned_at
FROM eb_customer_care_task
WHERE tenant_id='0' AND organization_path='/1/2/'
  AND planned_at>=UNIX_TIMESTAMP()
ORDER BY planned_at ASC,id ASC LIMIT 50;

SELECT 'CARE_EXPLAIN_MEMBER_HISTORY' AS explain_marker;
EXPLAIN SELECT id,record_key,business_date,status
FROM eb_customer_care_record
WHERE tenant_id='0' AND member_id=1
ORDER BY business_date DESC,id DESC LIMIT 50;

SELECT 'CARE_EXPLAIN_TASK_OPERATIONS' AS explain_marker;
EXPLAIN SELECT id,operation_type,occurred_at
FROM eb_customer_care_operation
WHERE tenant_id='0' AND task_id=1
ORDER BY occurred_at ASC,id ASC LIMIT 100;

SELECT
  @care_c1_upgrade_key_used AS c1_dependency_registered,
  @care_employee_upgrade_key_used AS employee_dependency_registered,
  @care_c1_receipt_table_count AS c1_receipt_table_exact,
  @care_c1_receipt_column_count AS c1_receipt_column_count,
  @care_c1_receipt_ascii_contract_count AS c1_receipt_ascii_contract_count,
  @care_c1_receipt_payload_contract_count AS c1_receipt_payload_contract_count,
  @care_c1_receipt_unique_contract_count AS c1_receipt_unique_contract_count,
  @care_employee_table_count AS employee_table_count,
  @care_employee_column_contract_count AS employee_column_contract_count,
  @care_employee_id_column_contract_count AS employee_id_column_contract_count,
  @care_employee_index_contract_count AS employee_index_contract_count,
  @care_invalid_active_assignment_count AS invalid_active_assignment_count,
  @care_table_count AS table_count,
  @care_engine_count AS innodb_utf8mb4_table_count,
  @care_column_count AS column_count,
  @care_ascii_contract_count AS ascii_bin_contract_count,
  @care_nullable_task_contract AS nullable_task_contract,
  @care_nullable_next_task_contract AS nullable_next_task_contract,
  @care_index_count AS index_count,
  @care_prefix_index_count AS prefix_index_count,
  @care_unique_contract_count AS unique_contract_count,
  @care_bad_tasks AS bad_task_rows,
  @care_bad_records AS bad_record_rows,
  @care_bad_operations AS bad_operation_rows,
  @care_failures AS verify_failure_count;

SET @care_finish_sql := IF(
  @care_failures=0,
  'SELECT ''POSTCHECK_OK'' AS verify_result',
  'SELECT * FROM STOP_CUSTOMER_CARE_POSTCHECK_FAILED'
);
PREPARE care_finish_stmt FROM @care_finish_sql;
EXECUTE care_finish_stmt;
DEALLOCATE PREPARE care_finish_stmt;
