-- Read-only interrupted-DDL validator for upgrade_key
-- 20260729-003-c2-entitlement-provider-dependencies.
SET NAMES utf8mb4;
SET @c2p_db := DATABASE();

SELECT COUNT(*) INTO @c2p_target_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME IN (
  'eb_cashier_v3_entitlement_debt_guard',
  'eb_cashier_v3_entitlement_debt_guard_mutation',
  'eb_cashier_v3_staff_profile_version',
  'eb_cashier_v3_entitlement_occupation_version'
);

SELECT COUNT(*) INTO @c2p_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME='eb_database_upgrade_log';
SET @c2p_registered := 0;
SET @c2p_registered_sql := IF(
  @c2p_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @c2p_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-003-c2-entitlement-provider-dependencies''',
  'SELECT 0 INTO @c2p_registered'
);
PREPARE c2p_registered_stmt FROM @c2p_registered_sql;
EXECUTE c2p_registered_stmt;
DEALLOCATE PREPARE c2p_registered_stmt;

SET @c2p_guard_rows := 0;
SET @c2p_guard_sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME='eb_cashier_v3_entitlement_debt_guard')=1,
  'SELECT COUNT(*) INTO @c2p_guard_rows FROM eb_cashier_v3_entitlement_debt_guard',
  'SELECT 0 INTO @c2p_guard_rows'
);
PREPARE c2p_guard_stmt FROM @c2p_guard_sql;
EXECUTE c2p_guard_stmt;
DEALLOCATE PREPARE c2p_guard_stmt;

SET @c2p_mutation_rows := 0;
SET @c2p_mutation_sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME='eb_cashier_v3_entitlement_debt_guard_mutation')=1,
  'SELECT COUNT(*) INTO @c2p_mutation_rows FROM eb_cashier_v3_entitlement_debt_guard_mutation',
  'SELECT 0 INTO @c2p_mutation_rows'
);
PREPARE c2p_mutation_stmt FROM @c2p_mutation_sql;
EXECUTE c2p_mutation_stmt;
DEALLOCATE PREPARE c2p_mutation_stmt;

SET @c2p_staff_rows := 0;
SET @c2p_staff_sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME='eb_cashier_v3_staff_profile_version')=1,
  'SELECT COUNT(*) INTO @c2p_staff_rows FROM eb_cashier_v3_staff_profile_version',
  'SELECT 0 INTO @c2p_staff_rows'
);
PREPARE c2p_staff_stmt FROM @c2p_staff_sql;
EXECUTE c2p_staff_stmt;
DEALLOCATE PREPARE c2p_staff_stmt;

SET @c2p_occupation_rows := 0;
SET @c2p_occupation_sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME='eb_cashier_v3_entitlement_occupation_version')=1,
  'SELECT COUNT(*) INTO @c2p_occupation_rows FROM eb_cashier_v3_entitlement_occupation_version',
  'SELECT 0 INTO @c2p_occupation_rows'
);
PREPARE c2p_occupation_stmt FROM @c2p_occupation_sql;
EXECUTE c2p_occupation_stmt;
DEALLOCATE PREPARE c2p_occupation_stmt;

SELECT COUNT(*) INTO @c2p_exact_existing_count
FROM (
  SELECT t.TABLE_NAME
  FROM information_schema.TABLES t
  LEFT JOIN information_schema.COLUMNS c
    ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME
  WHERE t.TABLE_SCHEMA=@c2p_db AND t.TABLE_NAME IN (
    'eb_cashier_v3_entitlement_debt_guard',
    'eb_cashier_v3_entitlement_debt_guard_mutation',
    'eb_cashier_v3_staff_profile_version',
    'eb_cashier_v3_entitlement_occupation_version'
  )
  GROUP BY t.TABLE_NAME,t.ENGINE,t.TABLE_COLLATION
  HAVING t.ENGINE='InnoDB' AND t.TABLE_COLLATION='utf8mb4_general_ci'
    AND COUNT(*)=CASE t.TABLE_NAME
      WHEN 'eb_cashier_v3_entitlement_debt_guard' THEN 7
      WHEN 'eb_cashier_v3_entitlement_debt_guard_mutation' THEN 9
      WHEN 'eb_cashier_v3_staff_profile_version' THEN 10
      WHEN 'eb_cashier_v3_entitlement_occupation_version' THEN 11
      ELSE -1 END
    AND SUM(CONCAT(t.TABLE_NAME,'.',c.COLUMN_NAME) IN (
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
    ))=CASE t.TABLE_NAME
      WHEN 'eb_cashier_v3_entitlement_debt_guard' THEN 7
      WHEN 'eb_cashier_v3_entitlement_debt_guard_mutation' THEN 9
      WHEN 'eb_cashier_v3_staff_profile_version' THEN 10
      WHEN 'eb_cashier_v3_entitlement_occupation_version' THEN 11
      ELSE -1 END
) c2p_exact_tables;

SELECT COUNT(*) INTO @c2p_unique_existing_count
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
) c2p_unique_existing;

SET @c2p_total_rows := @c2p_guard_rows + @c2p_mutation_rows + @c2p_staff_rows + @c2p_occupation_rows;
SET @c2p_partial_ready := @c2p_target_count BETWEEN 1 AND 3
  AND @c2p_upgrade_log_exists=1
  AND @c2p_registered=0
  AND @c2p_total_rows=0
  AND @c2p_exact_existing_count=@c2p_target_count
  AND @c2p_unique_existing_count=@c2p_target_count;

SELECT
  @c2p_target_count AS target_table_count,
  @c2p_exact_existing_count AS exact_existing_table_count,
  @c2p_unique_existing_count AS exact_unique_contract_count,
  @c2p_total_rows AS target_row_count,
  @c2p_registered AS upgrade_registered,
  @c2p_partial_ready AS partial_recovery_ready;

SET @c2p_finish_sql := CASE
  WHEN @c2p_target_count=0 THEN 'SELECT * FROM STOP_C2_PROVIDER_PARTIAL_ZERO_TABLES'
  WHEN @c2p_target_count=4 THEN 'SELECT * FROM STOP_C2_PROVIDER_PARTIAL_FULL_INSTALL'
  WHEN @c2p_upgrade_log_exists<>1 THEN 'SELECT * FROM STOP_C2_PROVIDER_PARTIAL_UPGRADE_LOG_MISSING'
  WHEN @c2p_registered<>0 THEN 'SELECT * FROM STOP_C2_PROVIDER_PARTIAL_ALREADY_REGISTERED'
  WHEN @c2p_total_rows<>0 THEN 'SELECT * FROM STOP_C2_PROVIDER_PARTIAL_NONEMPTY'
  WHEN @c2p_exact_existing_count<>@c2p_target_count OR @c2p_unique_existing_count<>@c2p_target_count THEN 'SELECT * FROM STOP_C2_PROVIDER_PARTIAL_HETEROGENEOUS'
  ELSE 'SELECT ''PARTIAL_DDL_RECOVERY_READY'' AS recovery_result'
END;
PREPARE c2p_finish_stmt FROM @c2p_finish_sql;
EXECUTE c2p_finish_stmt;
DEALLOCATE PREPARE c2p_finish_stmt;
