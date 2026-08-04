-- upgrade_key: 20260729-006-cashier-v3-checkout-source-authority
-- Read-only partial-DDL audit. It never drops, alters or creates an object.
SET NAMES utf8mb4;
SET @checkout_source_db := DATABASE();

SELECT COUNT(*) INTO @checkout_source_target_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_source_db
  AND TABLE_NAME='eb_cashier_v3_checkout_source_reference';

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

SELECT COUNT(*) INTO @checkout_source_engine_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_source_db
  AND TABLE_NAME='eb_cashier_v3_checkout_source_reference'
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

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

SET @checkout_source_recovery_state := CASE
  WHEN @checkout_source_target_count=0 THEN 'ABSENT_SAFE_TO_RUN_PRECHECK_AND_APPLY'
  WHEN @checkout_source_target_count=1
    AND @checkout_source_column_count=12
    AND @checkout_source_canonical_column_count=12
    AND @checkout_source_engine_count=1
    AND @checkout_source_unique_count=1
    THEN 'STRUCTURALLY_COMPLETE_RUN_POSTCHECK_DO_NOT_REAPPLY'
  ELSE 'PARTIAL_OR_DRIFTED_STOP_AND_REVIEW'
END;

SELECT
  @checkout_source_recovery_state AS recovery_state,
  @checkout_source_target_count AS target_table_count,
  @checkout_source_column_count AS column_count,
  @checkout_source_canonical_column_count AS canonical_column_count,
  @checkout_source_engine_count AS engine_contract_count,
  @checkout_source_unique_count AS unique_contract_count;

SET @checkout_source_finish_sql := IF(
  @checkout_source_recovery_state='PARTIAL_OR_DRIFTED_STOP_AND_REVIEW',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''checkout source authority partial DDL requires manual review''',
  'SELECT ''RECOVERY_AUDIT_OK'' AS recovery_audit_result'
);
PREPARE checkout_source_finish_stmt FROM @checkout_source_finish_sql;
EXECUTE checkout_source_finish_stmt;
DEALLOCATE PREPARE checkout_source_finish_stmt;
