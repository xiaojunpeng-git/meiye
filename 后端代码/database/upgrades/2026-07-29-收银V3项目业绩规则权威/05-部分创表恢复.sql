-- upgrade_key: 20260729-016-cashier-v3-project-performance-rule-v1
-- Read-only, non-destructive validator for a missing or prematurely-created target.
SET NAMES utf8mb4;
SET @pr_db := DATABASE();

SELECT COUNT(*) INTO @pr_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@pr_db AND TABLE_NAME='eb_database_upgrade_log';
SET @pr_registered := 0;
SET @pr_registered_sql := IF(
  @pr_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @pr_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-016-cashier-v3-project-performance-rule-v1''',
  'SELECT 0 INTO @pr_registered'
);
PREPARE pr_registered_stmt FROM @pr_registered_sql;
EXECUTE pr_registered_stmt;
DEALLOCATE PREPARE pr_registered_stmt;

SELECT COUNT(*) INTO @pr_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@pr_db AND TABLE_NAME='eb_cashier_v3_project_performance_rule';
SELECT COUNT(*) INTO @pr_engine_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@pr_db AND TABLE_NAME='eb_cashier_v3_project_performance_rule'
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';
SELECT COUNT(*) INTO @pr_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@pr_db AND TABLE_NAME='eb_cashier_v3_project_performance_rule';
SELECT COUNT(*) INTO @pr_exact_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@pr_db AND TABLE_NAME='eb_cashier_v3_project_performance_rule' AND (
  (COLUMN_NAME='id' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL AND EXTRA='auto_increment') OR
  (COLUMN_NAME='tenant_id' AND COLUMN_TYPE='varchar(32)' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin') OR
  (COLUMN_NAME='project_id' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL) OR
  (COLUMN_NAME='consumption_mode' AND COLUMN_TYPE='varchar(48)' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin') OR
  (COLUMN_NAME='consumption_configured_unit_amount_cents' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0') OR
  (COLUMN_NAME='labor_mode' AND COLUMN_TYPE='varchar(48)' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin') OR
  (COLUMN_NAME='labor_configured_unit_amount_cents' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0') OR
  (COLUMN_NAME='current_version' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='1') OR
  (COLUMN_NAME='created_at' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL) OR
  (COLUMN_NAME='updated_at' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL)
);
SELECT COUNT(*) INTO @pr_exact_index_count
FROM (
  SELECT INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@pr_db AND TABLE_NAME='eb_cashier_v3_project_performance_rule'
  GROUP BY INDEX_NAME
  HAVING CONCAT(INDEX_NAME,'|',non_unique,'|',index_columns) IN (
    'PRIMARY|0|id','uk_tenant_project|0|tenant_id,project_id',
    'idx_tenant_updated|1|tenant_id,updated_at,id'
  )
) pr_exact_indexes;
SELECT COUNT(*) INTO @pr_index_count
FROM (
  SELECT INDEX_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@pr_db AND TABLE_NAME='eb_cashier_v3_project_performance_rule'
  GROUP BY INDEX_NAME
) pr_all_indexes;

SET @pr_row_count := 0;
SET @pr_row_sql := IF(
  @pr_table_count=1,
  'SELECT COUNT(*) INTO @pr_row_count FROM eb_cashier_v3_project_performance_rule',
  'SELECT 0 INTO @pr_row_count'
);
PREPARE pr_row_stmt FROM @pr_row_sql;
EXECUTE pr_row_stmt;
DEALLOCATE PREPARE pr_row_stmt;

SET @pr_structure_ready := @pr_table_count=0 OR (
  @pr_table_count=1 AND @pr_engine_count=1 AND @pr_column_count=10
  AND @pr_exact_column_count=10 AND @pr_index_count=3 AND @pr_exact_index_count=3
);
SET @pr_recovery_ready := @pr_db IS NOT NULL AND @pr_db<>''
  AND @pr_upgrade_log_exists=1 AND @pr_registered=0
  AND @pr_row_count=0 AND @pr_structure_ready=1;

SELECT @pr_table_count AS target_table_count,@pr_row_count AS target_row_count,
  @pr_exact_column_count AS exact_column_count,@pr_exact_index_count AS exact_index_count,
  @pr_registered AS already_registered,@pr_recovery_ready AS recovery_ready;

SET @pr_finish_sql := IF(
  @pr_recovery_ready=1,
  'SELECT ''RECOVERY_READY_RUN_CANONICAL_APPLY'' AS recovery_result',
  'SELECT * FROM STOP_CASHIER_V3_PROJECT_PERFORMANCE_RULE_RECOVERY_FAILED'
);
PREPARE pr_finish_stmt FROM @pr_finish_sql;
EXECUTE pr_finish_stmt;
DEALLOCATE PREPARE pr_finish_stmt;
