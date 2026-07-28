-- upgrade_key: 20260728-004-unified-query-custom-fields
-- Read-only precheck. Seven target tables must all be absent or all match exactly.
SET NAMES utf8mb4;
SET SESSION group_concat_max_len=1048576;
SET @uq_db := DATABASE();
SET @uq_failures := 0;

SELECT @uq_db AS db_name, VERSION() AS mysql_version;

SELECT COUNT(*) INTO @uq_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@uq_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @uq_upgrade_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@uq_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';

SET @uq_c1_key_used := 0;
SET @uq_upgrade_key_used := 0;
SET @uq_log_sql := IF(
  @uq_upgrade_log_exists=1 AND @uq_upgrade_key_column=1,
  'SELECT SUM(upgrade_key=''20260727-001-cashier-v3-command-idem''),SUM(upgrade_key=''20260728-004-unified-query-custom-fields'') INTO @uq_c1_key_used,@uq_upgrade_key_used FROM eb_database_upgrade_log',
  'SET @uq_c1_key_used:=0,@uq_upgrade_key_used:=0'
);
PREPARE uq_log_stmt FROM @uq_log_sql;
EXECUTE uq_log_stmt;
DEALLOCATE PREPARE uq_log_stmt;
SET @uq_c1_key_used := IFNULL(@uq_c1_key_used,0);
SET @uq_upgrade_key_used := IFNULL(@uq_upgrade_key_used,0);

SELECT COUNT(*) INTO @uq_resource_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@uq_db AND TABLE_NAME='eb_cashier_v3_resource_version'
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';
SELECT COUNT(*) INTO @uq_resource_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@uq_db AND TABLE_NAME='eb_cashier_v3_resource_version'
  AND COLUMN_NAME IN ('scope_type','scope_id','resource_kind','resource_id','current_version');
SELECT COUNT(*) INTO @uq_resource_unique
FROM (
  SELECT INDEX_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@uq_db AND TABLE_NAME='eb_cashier_v3_resource_version'
    AND INDEX_NAME='uk_scope_resource' AND NON_UNIQUE=0
  GROUP BY INDEX_NAME
  HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
    ='scope_type,scope_id,resource_kind,resource_id'
) uq_resource_index;

SET @uq_failures := @uq_failures
  + IF(@uq_upgrade_log_exists=1,0,1)
  + IF(@uq_upgrade_key_column=1,0,1)
  + IF(@uq_c1_key_used=1,0,1)
  + IF(@uq_upgrade_key_used=0,0,1)
  + IF(@uq_resource_table=1,0,1)
  + IF(@uq_resource_columns=5,0,1)
  + IF(@uq_resource_unique=1,0,1);

SELECT COUNT(*),SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,ENGINE,TABLE_COLLATION)
  ORDER BY BINARY TABLE_NAME SEPARATOR '\n'
),256) INTO @uq_table_count,@uq_table_sha
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@uq_db AND TABLE_NAME IN (
  'eb_unified_query_custom_field',
  'eb_unified_query_custom_field_version',
  'eb_unified_query_field_alias_set',
  'eb_unified_query_field_alias',
  'eb_unified_query_field_reference',
  'eb_unified_query_export_task',
  'eb_unified_query_preference'
);

SELECT COUNT(*),SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
    IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),
    IFNULL(CHARACTER_SET_NAME,''),IFNULL(COLLATION_NAME,''),IFNULL(EXTRA,''))
  ORDER BY BINARY TABLE_NAME,ORDINAL_POSITION SEPARATOR '\n'
),256) INTO @uq_column_count,@uq_column_sha
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@uq_db AND TABLE_NAME IN (
  'eb_unified_query_custom_field',
  'eb_unified_query_custom_field_version',
  'eb_unified_query_field_alias_set',
  'eb_unified_query_field_alias',
  'eb_unified_query_field_reference',
  'eb_unified_query_export_task',
  'eb_unified_query_preference'
);

SELECT COUNT(*),SHA2(GROUP_CONCAT(
  CONCAT_WS('|',table_name,index_name,non_unique,index_type,index_columns,sub_parts)
  ORDER BY BINARY table_name,BINARY index_name SEPARATOR '\n'
),256) INTO @uq_index_count,@uq_index_sha
FROM (
  SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,
    MAX(NON_UNIQUE) AS non_unique,MAX(INDEX_TYPE) AS index_type,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    IF(SUM(SUB_PART IS NOT NULL)=0,'',
      GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@uq_db AND TABLE_NAME IN (
    'eb_unified_query_custom_field',
    'eb_unified_query_custom_field_version',
    'eb_unified_query_field_alias_set',
    'eb_unified_query_field_alias',
    'eb_unified_query_field_reference',
    'eb_unified_query_export_task',
    'eb_unified_query_preference'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
) uq_indexes;

SET @uq_expected_table_sha := '3694acc019506c9b70be3004543ada2d2e4b4187dc1cfb44d237b9a48207d116';
SET @uq_expected_column_sha := '514c0f4552f1773ebd5b7a4960ebc21f24765b424398df7e85e4f28499169903';
SET @uq_expected_index_sha := 'afb30b459d077d188fb7d2c9693560db75e5bd6eb5ae35160e79552ab2904904';
SET @uq_partial_bad := IF(@uq_table_count IN (0,7),0,1);
SET @uq_structure_bad := IF(
  @uq_table_count=7,
    IF(@uq_column_count=114,0,1)
    + IF(@uq_index_count=29,0,1)
    + IF(IFNULL(BINARY @uq_table_sha=BINARY @uq_expected_table_sha,0),0,1)
    + IF(IFNULL(BINARY @uq_column_sha=BINARY @uq_expected_column_sha,0),0,1)
    + IF(IFNULL(BINARY @uq_index_sha=BINARY @uq_expected_index_sha,0),0,1),
  0
);
SET @uq_failures := @uq_failures + @uq_partial_bad + @uq_structure_bad;

SET @uq_bad_rows := 0;
SET @uq_semantic_sql := IF(
  @uq_table_count=7 AND @uq_structure_bad=0,
  'SELECT
      (SELECT COUNT(*) FROM eb_unified_query_custom_field WHERE current_version=0 OR status NOT IN (''active'',''inactive'',''invalid'',''archived''))
    + (SELECT COUNT(*) FROM eb_unified_query_custom_field_version WHERE version=0 OR expression_hash='''' OR referenced_field_contract='''' OR status NOT IN (''active'',''inactive'',''invalid'',''archived''))
    + (SELECT COUNT(*) FROM eb_unified_query_field_alias_set WHERE current_version=0)
    + (SELECT COUNT(*) FROM eb_unified_query_field_alias WHERE alias_version=0 OR alias='''')
    + (SELECT COUNT(*) FROM eb_unified_query_field_reference WHERE field_version=0 OR status NOT IN (''active'',''upgrade_available'',''invalid'',''released''))
    + (SELECT COUNT(*) FROM eb_unified_query_export_task WHERE status NOT IN (''pending'',''running'',''succeeded'',''failed'',''blocked'',''expired'') OR (status<>''running'' AND (lease_token<>'''' OR lease_expires_at<>0)))
    + (SELECT COUNT(*) FROM eb_unified_query_preference WHERE current_version=0 OR settings_hash='''')
    INTO @uq_bad_rows',
  'SET @uq_bad_rows:=0'
);
PREPARE uq_semantic_stmt FROM @uq_semantic_sql;
EXECUTE uq_semantic_stmt;
DEALLOCATE PREPARE uq_semantic_stmt;
SET @uq_failures := @uq_failures + IF(@uq_bad_rows=0,0,1);

SELECT
  @uq_upgrade_log_exists AS upgrade_log_exists,
  @uq_c1_key_used AS c1_dependency_registered,
  @uq_upgrade_key_used AS upgrade_key_used,
  @uq_resource_table AS resource_version_table_exact,
  @uq_table_count AS target_table_count,
  @uq_column_count AS target_column_count,
  @uq_column_sha AS target_column_sha,
  @uq_index_count AS target_index_count,
  @uq_index_sha AS target_index_sha,
  @uq_partial_bad AS partial_failure,
  @uq_structure_bad AS structure_failure,
  @uq_bad_rows AS semantic_bad_rows,
  @uq_failures AS precheck_failure_count;

SET @uq_finish_sql := IF(
  @uq_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_UNIFIED_QUERY_PRECHECK_FAILED'
);
PREPARE uq_finish_stmt FROM @uq_finish_sql;
EXECUTE uq_finish_stmt;
DEALLOCATE PREPARE uq_finish_stmt;
