-- upgrade_key: 20260729-016-cashier-v3-project-performance-rule-v1
SET NAMES utf8mb4;
SET @pr_db := DATABASE();
SET @pr_failures := 0;

SELECT COUNT(*) INTO @pr_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @pr_db
  AND TABLE_NAME = 'eb_cashier_v3_project_performance_rule'
  AND ENGINE = 'InnoDB'
  AND TABLE_COLLATION = 'utf8mb4_general_ci';

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

SELECT COUNT(*) INTO @pr_index_count
FROM (
  SELECT INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@pr_db AND TABLE_NAME='eb_cashier_v3_project_performance_rule'
  GROUP BY INDEX_NAME
) pr_all_indexes;

SELECT COUNT(*) INTO @pr_exact_index_count
FROM (
  SELECT INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@pr_db AND TABLE_NAME='eb_cashier_v3_project_performance_rule'
  GROUP BY INDEX_NAME
  HAVING CONCAT(INDEX_NAME,'|',non_unique,'|',index_columns) IN (
    'PRIMARY|0|id',
    'uk_tenant_project|0|tenant_id,project_id',
    'idx_tenant_updated|1|tenant_id,updated_at,id'
  )
) pr_exact_indexes;

SELECT COUNT(*) INTO @pr_bad_rows
FROM eb_cashier_v3_project_performance_rule
WHERE tenant_id='' OR project_id=0
  OR consumption_mode NOT IN ('actual_entitlement_amount','project_configured_amount')
  OR labor_mode NOT IN ('actual_entitlement_amount','project_configured_amount')
  OR consumption_configured_unit_amount_cents>100000000000
  OR labor_configured_unit_amount_cents>100000000000
  OR current_version=0 OR created_at=0 OR updated_at=0 OR updated_at<created_at;

SET @pr_failures := @pr_failures
  + IF(@pr_table_count=1,0,1)
  + IF(@pr_column_count=10 AND @pr_exact_column_count=10,0,1)
  + IF(@pr_index_count=3 AND @pr_exact_index_count=3,0,1)
  + IF(@pr_bad_rows=0,0,1);

SELECT @pr_table_count AS exact_table_count,@pr_exact_column_count AS exact_column_count,
  @pr_exact_index_count AS exact_index_count,@pr_bad_rows AS invalid_row_count,
  @pr_failures AS verification_failure_count;

SET @pr_finish_sql := IF(
  @pr_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_PROJECT_PERFORMANCE_RULE_POSTCHECK_FAILED'
);
PREPARE pr_finish_stmt FROM @pr_finish_sql;
EXECUTE pr_finish_stmt;
DEALLOCATE PREPARE pr_finish_stmt;
