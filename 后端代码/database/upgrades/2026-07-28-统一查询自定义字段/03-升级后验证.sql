-- upgrade_key: 20260728-004-unified-query-custom-fields
-- Exact post-upgrade verification. Register the upgrade key only after this succeeds.
SET NAMES utf8mb4;
SET SESSION group_concat_max_len=1048576;
SET @uq_db := DATABASE();
SET @uq_failures := 0;

SELECT @uq_db AS db_name, VERSION() AS mysql_version;

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
SET @uq_structure_bad :=
    IF(@uq_table_count=7,0,1)
  + IF(@uq_column_count=114,0,1)
  + IF(@uq_index_count=29,0,1)
  + IF(IFNULL(BINARY @uq_table_sha=BINARY @uq_expected_table_sha,0),0,1)
  + IF(IFNULL(BINARY @uq_column_sha=BINARY @uq_expected_column_sha,0),0,1)
  + IF(IFNULL(BINARY @uq_index_sha=BINARY @uq_expected_index_sha,0),0,1);
SET @uq_failures := @uq_failures + @uq_structure_bad;

SELECT COUNT(*) INTO @uq_bad_rows
FROM eb_unified_query_custom_field
WHERE current_version=0
  OR visibility NOT IN ('personal','shared')
  OR scope_type NOT IN ('account','store','organization_subtree','tenant')
  OR status NOT IN ('active','inactive','invalid','archived')
  OR return_type NOT IN ('text','amount','decimal','integer','date','datetime','boolean')
  OR complexity_score>100;

SELECT COUNT(*) INTO @uq_orphan_versions
FROM eb_unified_query_custom_field_version v
LEFT JOIN eb_unified_query_custom_field f ON f.id=v.custom_field_id
WHERE f.id IS NULL OR v.version=0 OR v.expression_hash=''
  OR v.referenced_field_contract='' OR v.complexity_score>100;

SELECT COUNT(*) INTO @uq_current_version_missing
FROM eb_unified_query_custom_field f
LEFT JOIN eb_unified_query_custom_field_version v
  ON v.custom_field_id=f.id AND v.version=f.current_version
WHERE v.id IS NULL;

SELECT COUNT(*) INTO @uq_bad_alias
FROM eb_unified_query_field_alias a
LEFT JOIN eb_unified_query_field_alias_set s
  ON s.tenant_id=a.tenant_id AND s.account_id=a.account_id AND s.page_code=a.page_code
WHERE s.id IS NULL OR a.alias_version=0 OR a.alias='';

SELECT COUNT(*) INTO @uq_bad_reference
FROM eb_unified_query_field_reference r
LEFT JOIN eb_unified_query_custom_field_version v
  ON v.custom_field_id=r.custom_field_id AND v.version=r.field_version
WHERE v.id IS NULL
  OR r.status NOT IN ('active','upgrade_available','invalid','released');

SELECT COUNT(*) INTO @uq_bad_export
FROM eb_unified_query_export_task
WHERE status NOT IN ('pending','running','succeeded','failed','blocked','expired')
  OR (status='running' AND (lease_token='' OR lease_expires_at=0))
  OR (status<>'running' AND (lease_token<>'' OR lease_expires_at<>0));

SELECT COUNT(*) INTO @uq_bad_preference
FROM eb_unified_query_preference
WHERE current_version=0 OR settings_hash='';

SET @uq_failures := @uq_failures
  + IF(@uq_bad_rows=0,0,1)
  + IF(@uq_orphan_versions=0,0,1)
  + IF(@uq_current_version_missing=0,0,1)
  + IF(@uq_bad_alias=0,0,1)
  + IF(@uq_bad_reference=0,0,1)
  + IF(@uq_bad_export=0,0,1)
  + IF(@uq_bad_preference=0,0,1);

-- Query-plan evidence: each common metadata lookup must select an intended index.
EXPLAIN SELECT id,field_key,current_version,status
FROM eb_unified_query_custom_field
WHERE tenant_id='0' AND page_code='member_list'
  AND visibility='personal' AND owner_account_id=1 AND status='active'
ORDER BY updated_at DESC,id DESC LIMIT 50;

EXPLAIN SELECT field_key,alias
FROM eb_unified_query_field_alias
WHERE tenant_id='0' AND account_id=1 AND page_code='member_list'
ORDER BY id ASC;

EXPLAIN SELECT consumer_type,consumer_id,field_version,status
FROM eb_unified_query_field_reference
WHERE tenant_id='0' AND field_key='cf_00000000000000000000' AND status='active';

SELECT 'UQ_EXPLAIN_PENDING_EXPORT_TASK' AS uq_explain_marker;
EXPLAIN SELECT task_no,status,created_at
FROM eb_unified_query_export_task
WHERE tenant_id='0' AND account_id=1 AND status='pending'
ORDER BY created_at DESC,id DESC LIMIT 20;

SELECT 'UQ_EXPLAIN_EXPIRED_EXPORT_LEASE' AS uq_explain_marker;
EXPLAIN SELECT task_no
FROM eb_unified_query_export_task
WHERE status='running' AND lease_expires_at>0 AND lease_expires_at<UNIX_TIMESTAMP()
ORDER BY id ASC LIMIT 20;

SELECT 'UQ_EXPLAIN_EXPORT_TASK_LOOKUP' AS uq_explain_marker;
EXPLAIN SELECT id,task_no
FROM eb_unified_query_export_task
WHERE task_no='uqe_matrix'
LIMIT 1;

SELECT
  @uq_table_count AS table_count,
  @uq_table_sha AS table_sha,
  @uq_column_count AS column_count,
  @uq_column_sha AS column_sha,
  @uq_index_count AS index_count,
  @uq_index_sha AS index_sha,
  @uq_bad_rows AS bad_custom_fields,
  @uq_orphan_versions AS orphan_versions,
  @uq_current_version_missing AS missing_current_versions,
  @uq_bad_alias AS bad_aliases,
  @uq_bad_reference AS bad_references,
  @uq_bad_export AS bad_exports,
  @uq_bad_preference AS bad_preferences,
  @uq_failures AS verify_failure_count;

SET @uq_finish_sql := IF(
  @uq_failures=0,
  'SELECT ''POSTCHECK_OK'' AS verify_result',
  'SELECT * FROM STOP_UNIFIED_QUERY_POSTCHECK_FAILED'
);
PREPARE uq_finish_stmt FROM @uq_finish_sql;
EXECUTE uq_finish_stmt;
DEALLOCATE PREPARE uq_finish_stmt;
