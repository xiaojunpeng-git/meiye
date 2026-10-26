-- upgrade_key: 20260729-010-cashier-v3-checkout-resource-plan
-- Read-only partial-DDL audit. It never drops, alters or creates an object.
SET NAMES utf8mb4;
SET @checkout_plan_db := DATABASE();

SELECT COUNT(*) INTO @checkout_plan_target_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_plan_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_resource_plan',
    'eb_cashier_v3_checkout_resource_plan_row'
  );

SELECT COUNT(*) INTO @checkout_plan_header_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_plan_db
  AND TABLE_NAME='eb_cashier_v3_checkout_resource_plan';

SELECT COUNT(*) INTO @checkout_plan_row_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_plan_db
  AND TABLE_NAME='eb_cashier_v3_checkout_resource_plan_row';

SELECT COUNT(*) INTO @checkout_plan_engine_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_plan_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_resource_plan',
    'eb_cashier_v3_checkout_resource_plan_row'
  )
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

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

SET @checkout_plan_recovery_state := CASE
  WHEN @checkout_plan_target_count=0 THEN 'ABSENT_SAFE_TO_RUN_PRECHECK_AND_APPLY'
  WHEN @checkout_plan_target_count=2
    AND @checkout_plan_header_columns=14
    AND @checkout_plan_row_columns=19
    AND @checkout_plan_engine_count=2
    AND @checkout_plan_unique_count=3
    THEN 'STRUCTURALLY_COMPLETE_RUN_POSTCHECK_DO_NOT_REAPPLY'
  ELSE 'PARTIAL_OR_DRIFTED_STOP_AND_REVIEW'
END;

SELECT
  @checkout_plan_recovery_state AS recovery_state,
  @checkout_plan_target_count AS target_table_count,
  @checkout_plan_header_columns AS header_column_count,
  @checkout_plan_row_columns AS row_column_count,
  @checkout_plan_engine_count AS engine_contract_count,
  @checkout_plan_unique_count AS unique_contract_count;

SET @checkout_plan_finish_sql := IF(
  @checkout_plan_recovery_state='PARTIAL_OR_DRIFTED_STOP_AND_REVIEW',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''checkout resource plan partial DDL requires manual review''',
  'SELECT ''RECOVERY_AUDIT_OK'' AS recovery_audit_result'
);
PREPARE checkout_plan_finish_stmt FROM @checkout_plan_finish_sql;
EXECUTE checkout_plan_finish_stmt;
DEALLOCATE PREPARE checkout_plan_finish_stmt;
