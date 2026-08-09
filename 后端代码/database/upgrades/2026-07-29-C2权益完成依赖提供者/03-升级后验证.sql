-- upgrade_key: 20260729-003-c2-entitlement-provider-dependencies
SET NAMES utf8mb4;
SET @c2p_db := DATABASE();
SET @c2p_failures := 0;

SELECT COUNT(*) INTO @c2p_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME IN (
  'eb_cashier_v3_entitlement_debt_guard',
  'eb_cashier_v3_entitlement_debt_guard_mutation',
  'eb_cashier_v3_staff_profile_version',
  'eb_cashier_v3_entitlement_occupation_version'
);

SELECT COUNT(*) INTO @c2p_engine_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME IN (
  'eb_cashier_v3_entitlement_debt_guard',
  'eb_cashier_v3_entitlement_debt_guard_mutation',
  'eb_cashier_v3_staff_profile_version',
  'eb_cashier_v3_entitlement_occupation_version'
) AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @c2p_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2p_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_cashier_v3_entitlement_debt_guard.id',
  'eb_cashier_v3_entitlement_debt_guard.tenant_id',
  'eb_cashier_v3_entitlement_debt_guard.origin_order_id',
  'eb_cashier_v3_entitlement_debt_guard.current_version',
  'eb_cashier_v3_entitlement_debt_guard.last_action',
  'eb_cashier_v3_entitlement_debt_guard.created_at',
  'eb_cashier_v3_entitlement_debt_guard.updated_at',
  'eb_cashier_v3_entitlement_debt_guard_mutation.id',
  'eb_cashier_v3_entitlement_debt_guard_mutation.tenant_id',
  'eb_cashier_v3_entitlement_debt_guard_mutation.mutation_key',
  'eb_cashier_v3_entitlement_debt_guard_mutation.origin_order_id',
  'eb_cashier_v3_entitlement_debt_guard_mutation.action',
  'eb_cashier_v3_entitlement_debt_guard_mutation.request_fingerprint',
  'eb_cashier_v3_entitlement_debt_guard_mutation.guard_version_before',
  'eb_cashier_v3_entitlement_debt_guard_mutation.guard_version_after',
  'eb_cashier_v3_entitlement_debt_guard_mutation.created_at',
  'eb_cashier_v3_staff_profile_version.id',
  'eb_cashier_v3_staff_profile_version.tenant_id',
  'eb_cashier_v3_staff_profile_version.staff_id',
  'eb_cashier_v3_staff_profile_version.employee_id_snapshot',
  'eb_cashier_v3_staff_profile_version.store_id_snapshot',
  'eb_cashier_v3_staff_profile_version.profile_fingerprint',
  'eb_cashier_v3_staff_profile_version.current_version',
  'eb_cashier_v3_staff_profile_version.last_action',
  'eb_cashier_v3_staff_profile_version.created_at',
  'eb_cashier_v3_staff_profile_version.updated_at',
  'eb_cashier_v3_entitlement_occupation_version.id',
  'eb_cashier_v3_entitlement_occupation_version.tenant_id',
  'eb_cashier_v3_entitlement_occupation_version.source_kind',
  'eb_cashier_v3_entitlement_occupation_version.source_id',
  'eb_cashier_v3_entitlement_occupation_version.store_id_snapshot',
  'eb_cashier_v3_entitlement_occupation_version.entitlement_source_detail_id_snapshot',
  'eb_cashier_v3_entitlement_occupation_version.authority_fingerprint',
  'eb_cashier_v3_entitlement_occupation_version.current_version',
  'eb_cashier_v3_entitlement_occupation_version.last_action',
  'eb_cashier_v3_entitlement_occupation_version.created_at',
  'eb_cashier_v3_entitlement_occupation_version.updated_at'
);

SELECT COUNT(*) INTO @c2p_unique_count
FROM (
  SELECT TABLE_NAME,INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME IN (
    'eb_cashier_v3_entitlement_debt_guard',
    'eb_cashier_v3_entitlement_debt_guard_mutation',
    'eb_cashier_v3_staff_profile_version',
    'eb_cashier_v3_entitlement_occupation_version'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING non_unique=0 AND CONCAT(TABLE_NAME,'|',INDEX_NAME,'|',index_columns) IN (
    'eb_cashier_v3_entitlement_debt_guard|uk_tenant_origin_order|tenant_id,origin_order_id',
    'eb_cashier_v3_entitlement_debt_guard_mutation|uk_tenant_mutation_key|tenant_id,mutation_key',
    'eb_cashier_v3_staff_profile_version|uk_tenant_staff|tenant_id,staff_id',
    'eb_cashier_v3_entitlement_occupation_version|uk_tenant_kind_source|tenant_id,source_kind,source_id'
  )
) c2p_unique_indexes;

SELECT COUNT(*) INTO @c2p_bad_guard
FROM eb_cashier_v3_entitlement_debt_guard
WHERE tenant_id='' OR origin_order_id=0 OR current_version=0 OR created_at=0 OR updated_at=0;

SELECT COUNT(*) INTO @c2p_bad_mutation
FROM eb_cashier_v3_entitlement_debt_guard_mutation
WHERE tenant_id='' OR mutation_key='' OR origin_order_id=0 OR action=''
  OR request_fingerprint NOT REGEXP '^[a-f0-9]{64}$'
  OR guard_version_before=0 OR guard_version_after<>guard_version_before+1 OR created_at=0;

SELECT COUNT(*) INTO @c2p_bad_staff
FROM eb_cashier_v3_staff_profile_version
WHERE tenant_id='' OR staff_id=0 OR employee_id_snapshot=0 OR store_id_snapshot=0
  OR profile_fingerprint NOT REGEXP '^[a-f0-9]{64}$' OR current_version=0
  OR created_at=0 OR updated_at=0;

SELECT COUNT(*) INTO @c2p_bad_occupation
FROM eb_cashier_v3_entitlement_occupation_version
WHERE tenant_id='' OR source_kind NOT IN ('reservation','service_order') OR source_id=0
  OR store_id_snapshot=0 OR entitlement_source_detail_id_snapshot=0
  OR authority_fingerprint NOT REGEXP '^[a-f0-9]{64}$' OR current_version=0
  OR created_at=0 OR updated_at=0;

SET @c2p_failures := @c2p_failures
  + IF(@c2p_table_count=4,0,1)
  + IF(@c2p_engine_count=4,0,1)
  + IF(@c2p_column_count=37,0,1)
  + IF(@c2p_unique_count=4,0,1)
  + IF(@c2p_bad_guard=0,0,1)
  + IF(@c2p_bad_mutation=0,0,1)
  + IF(@c2p_bad_staff=0,0,1)
  + IF(@c2p_bad_occupation=0,0,1);

SELECT
  @c2p_table_count AS table_count,
  @c2p_engine_count AS innodb_table_count,
  @c2p_column_count AS required_column_count,
  @c2p_unique_count AS unique_contract_count,
  @c2p_failures AS verification_failure_count;

SET @c2p_finish_sql := IF(
  @c2p_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_C2_ENTITLEMENT_PROVIDER_POSTCHECK_FAILED'
);
PREPARE c2p_finish_stmt FROM @c2p_finish_sql;
EXECUTE c2p_finish_stmt;
DEALLOCATE PREPARE c2p_finish_stmt;
